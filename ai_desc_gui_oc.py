"""
ai_desc_gui_oc.py

GUI-обёртка для удалённого запуска ai_generate_descriptions_oc.php на сервере
через SSH — версия ДЛЯ OcStore3/OpenCart3 (полностью отдельный файл и конфиг
от Bitrix-версии ai_desc_gui.py, изменения в одной версии не затрагивают другую).

Сама генерация выполняется на сервере, приложение подключается по SSH,
запускает задачу В ФОНЕ на сервере (независимо от SSH-соединения), и
периодически проверяет файл лога и статус процесса короткими запросами.

Такая схема выбрана специально: длинный прогон (сотни товаров, десятки
минут) через один непрерывный SSH-канал ненадёжен — соединение может
оборваться из-за сети. Даже если конкретная проверка не пройдёт (временная
проблема сети), приложение просто попробует снова через несколько секунд —
сам процесс генерации на сервере от этого никак не зависит.

Требования для запуска из исходников:
    pip install paramiko

Для сборки в отдельный .exe (на Windows):
    pip install paramiko pyinstaller
    pyinstaller --onefile --noconsole --name "ocstore_generator" ai_desc_gui_oc.py

Готовый .exe появится в папке dist/.
"""

import tkinter as tk
from tkinter import ttk, scrolledtext, messagebox, simpledialog
import threading
import queue
import json
import os
import sys
import time
import shlex

try:
    import paramiko
except ImportError:
    paramiko = None

if getattr(sys, "frozen", False):
    # Собранный PyInstaller --onefile .exe: __file__ указывает на временную
    # папку распаковки, которая удаляется после закрытия — конфиг там не
    # сохранится между запусками. Берём папку рядом с самим .exe.
    BASE_DIR = os.path.dirname(sys.executable)
else:
    BASE_DIR = os.path.dirname(os.path.abspath(__file__))

CONFIG_FILE = os.path.join(BASE_DIR, "config_oc.json")

POLL_INTERVAL_SEC = 3

DEFAULT_CONFIG = {
    "host": "",
    "port": 22,
    "username": "web",
    "password": "",
    "remote_path": "/var/www/fe-rus/data/www/fe-rus.ru/scripts/content-generator",
    "router_cheap_api_key": "",
    "router_cheap_model": "claude-sonnet-5",
}


def load_config():
    if os.path.exists(CONFIG_FILE):
        try:
            with open(CONFIG_FILE, "r", encoding="utf-8") as f:
                return {**DEFAULT_CONFIG, **json.load(f)}
        except Exception:
            pass
    return DEFAULT_CONFIG.copy()


def save_config(cfg):
    with open(CONFIG_FILE, "w", encoding="utf-8") as f:
        json.dump(cfg, f, ensure_ascii=False, indent=2)


def setup_text_editing_shortcuts(widget):
    """Контекстное меню + горячие клавиши по физическому коду клавиши.
    Нужно из-за особенности Tkinter: стандартные Ctrl+A/C/V/X определяются
    по символу, который печатает клавиша, а не по её физическому
    расположению — при русской раскладке это часто просто не срабатывает.
    Код клавиш ниже (65=A, 67=C, 86=V, 88=X) — это физические коды,
    одинаковые независимо от раскладки."""

    def select_all(event=None):
        widget.tag_add("sel", "1.0", "end")
        return "break"

    def cut(event=None):
        widget.event_generate("<<Cut>>")
        return "break"

    def copy(event=None):
        widget.event_generate("<<Copy>>")
        return "break"

    def paste(event=None):
        widget.event_generate("<<Paste>>")
        return "break"

    def on_key(event):
        if event.state & 0x4:
            if event.keycode == 65:
                return select_all()
            if event.keycode == 67:
                return copy()
            if event.keycode == 86:
                return paste()
            if event.keycode == 88:
                return cut()

    widget.bind("<Key>", on_key)

    menu = tk.Menu(widget, tearoff=0)
    menu.add_command(label="Вырезать", command=cut)
    menu.add_command(label="Копировать", command=copy)
    menu.add_command(label="Вставить", command=paste)
    menu.add_separator()
    menu.add_command(label="Выделить всё", command=select_all)

    def show_menu(event):
        menu.tk_popup(event.x_root, event.y_root)

    widget.bind("<Button-3>", show_menu)


class App:
    def __init__(self, root):
        self.root = root
        self.root.title("ФЕРУС — генерация описаний товаров")
        self.root.geometry("980x800")

        if paramiko is None:
            messagebox.showerror(
                "Не хватает библиотеки",
                "Не установлен модуль paramiko.\nВыполни: pip install paramiko",
            )

        self.cfg = load_config()
        self.output_queue = queue.Queue()
        self.ssh_client = None
        self.running = False
        self.stop_requested = False

        self._build_ui()
        self.root.after(100, self._poll_output)
        self.root.protocol("WM_DELETE_WINDOW", self._on_close)

    # ---------------------------------------------------------- UI

    def _build_ui(self):
        conn_frame = ttk.LabelFrame(self.root, text="Подключение к серверу (SSH)")
        conn_frame.pack(fill="x", padx=10, pady=8)

        ttk.Label(conn_frame, text="Хост:").grid(row=0, column=0, sticky="e", padx=4, pady=4)
        self.host_var = tk.StringVar(value=self.cfg["host"])
        ttk.Entry(conn_frame, textvariable=self.host_var, width=25).grid(row=0, column=1, padx=4, pady=4)

        ttk.Label(conn_frame, text="Порт:").grid(row=0, column=2, sticky="e", padx=4, pady=4)
        self.port_var = tk.StringVar(value=str(self.cfg["port"]))
        ttk.Entry(conn_frame, textvariable=self.port_var, width=6).grid(row=0, column=3, padx=4, pady=4)

        ttk.Label(conn_frame, text="Логин:").grid(row=1, column=0, sticky="e", padx=4, pady=4)
        self.user_var = tk.StringVar(value=self.cfg["username"])
        ttk.Entry(conn_frame, textvariable=self.user_var, width=25).grid(row=1, column=1, padx=4, pady=4)

        ttk.Label(conn_frame, text="Пароль:").grid(row=1, column=2, sticky="e", padx=4, pady=4)
        self.pass_var = tk.StringVar(value=self.cfg["password"])
        ttk.Entry(conn_frame, textvariable=self.pass_var, width=20, show="*").grid(row=1, column=3, padx=4, pady=4)

        ttk.Label(conn_frame, text="Путь к скрипту на сервере:").grid(row=2, column=0, sticky="e", padx=4, pady=4)
        self.path_var = tk.StringVar(value=self.cfg["remote_path"])
        ttk.Entry(conn_frame, textvariable=self.path_var, width=55).grid(
            row=2, column=1, columnspan=3, sticky="w", padx=4, pady=4
        )

        ttk.Label(conn_frame, text="Ключ API (router.cheap, sk-...):").grid(row=3, column=0, sticky="e", padx=4, pady=4)
        self.api_key_var = tk.StringVar(value=self.cfg["router_cheap_api_key"])
        ttk.Entry(conn_frame, textvariable=self.api_key_var, width=55, show="*").grid(
            row=3, column=1, columnspan=3, sticky="w", padx=4, pady=4
        )

        ttk.Label(conn_frame, text="Модель:").grid(row=4, column=0, sticky="e", padx=4, pady=4)
        self.model_var = tk.StringVar(value=self.cfg["router_cheap_model"])
        ttk.Entry(conn_frame, textvariable=self.model_var, width=25).grid(row=4, column=1, sticky="w", padx=4, pady=4)

        ttk.Button(conn_frame, text="Сохранить настройки подключения", command=self._save_settings).grid(
            row=5, column=0, columnspan=4, pady=6
        )

        # Статус и лог — общие для обеих вкладок, размещены ВЫШЕ вкладок и с
        # фиксированной высотой (без expand), чтобы вкладка с кнопками ниже
        # не "съедала" под себя всё окно и лог оставался виден всегда, на
        # какой бы вкладке пользователь ни находился.
        self._build_shared_status_and_log()

        # Вкладки верхнего уровня: "Генератор текста на товары" — весь
        # существующий функционал (без изменений, просто под одной вкладкой),
        # "Создание SEO-страниц" — новый функционал для модуля OCFilter
        # (страницы фильтров категорий, например "Арматура сталь 09Г2С").
        self.main_notebook = ttk.Notebook(self.root)
        self.main_notebook.pack(fill="both", expand=True, padx=10, pady=8)

        products_tab = ttk.Frame(self.main_notebook)
        ocfilter_tab = ttk.Frame(self.main_notebook)
        self.main_notebook.add(products_tab, text="📦 Генератор текста на товары")
        self.main_notebook.add(ocfilter_tab, text="🔍 Создание SEO-страниц")

        self._build_products_tab(products_tab)
        self._build_ocfilter_tab(ocfilter_tab)

    def _build_products_tab(self, parent):
        run_frame = ttk.LabelFrame(parent, text="Запуск генерации")
        run_frame.pack(fill="x", padx=0, pady=8)

        ttk.Label(run_frame, text="ID раздела:").grid(row=0, column=0, sticky="e", padx=4, pady=4)
        self.section_var = tk.StringVar()
        ttk.Entry(run_frame, textvariable=self.section_var, width=12).grid(row=0, column=1, padx=4, pady=4)

        ttk.Label(run_frame, text="Лимит товаров (необязательно):").grid(row=0, column=2, sticky="e", padx=4, pady=4)
        self.limit_var = tk.StringVar()
        ttk.Entry(run_frame, textvariable=self.limit_var, width=8).grid(row=0, column=3, padx=4, pady=4)

        btn_frame = ttk.Frame(run_frame)
        btn_frame.grid(row=1, column=0, columnspan=4, pady=(4, 4))

        self.review_btn = ttk.Button(btn_frame, text="Сгенерировать описания", command=self._start_review)
        self.review_btn.pack(side="left", padx=4)

        self.apply_btn = ttk.Button(btn_frame, text="Записать на сайт (apply)", command=self._start_apply)
        self.apply_btn.pack(side="left", padx=4)

        self.stop_btn = ttk.Button(btn_frame, text="Прекратить слежение", command=self._stop, state="disabled")
        self.stop_btn.pack(side="left", padx=4)

        self.check_btn = ttk.Button(btn_frame, text="Проверить кол-во сгенерированных", command=self._check_count)
        self.check_btn.pack(side="left", padx=4)

        self.indexnow_btn = ttk.Button(btn_frame, text="Отправить в IndexNow", command=self._start_indexnow)
        self.indexnow_btn.pack(side="left", padx=4)

        btn_frame2 = ttk.Frame(run_frame)
        btn_frame2.grid(row=2, column=0, columnspan=4, pady=(0, 8))

        self.edit_prompt_btn = ttk.Button(btn_frame2, text="Редактировать промпт", command=self._open_prompt_editor)
        self.edit_prompt_btn.pack(side="left", padx=4)

        self.delete_report_btn = ttk.Button(
            btn_frame2, text="Удалить текущие тексты (для полной перегенерации)",
            command=self._delete_report,
        )
        self.delete_report_btn.pack(side="left", padx=4)

        self.view_report_btn = ttk.Button(
            btn_frame2, text="Посмотреть тексты", command=self._view_products_report,
        )
        self.view_report_btn.pack(side="left", padx=4)

        self.ready_ids_btn = ttk.Button(
            btn_frame2, text="Просмотр ID с готовыми текстами",
            command=lambda: self._open_ready_ids_window("products"),
        )
        self.ready_ids_btn.pack(side="left", padx=4)

    def _build_shared_status_and_log(self):
        # Статус и лог — общие для обеих вкладок (и генерация текста
        # товаров, и создание SEO-страниц используют один и тот же механизм
        # фоновых задач на сервере и один и тот же лог их выполнения).
        status_frame = ttk.Frame(self.root)
        status_frame.pack(fill="x", padx=12)
        ttk.Label(status_frame, text="Статус:").pack(side="left")
        self.status_var = tk.StringVar(value="Готово")
        ttk.Label(status_frame, textvariable=self.status_var, foreground="blue").pack(side="left", padx=6)

        # Фиксированная высота (через height у ScrolledText) и БЕЗ expand —
        # так лог гарантированно виден сразу, а не "съедается" вкладками,
        # которые упакованы ниже с expand=True и забирают себе весь
        # оставшийся объём окна.
        log_frame = ttk.LabelFrame(self.root, text="Лог выполнения")
        log_frame.pack(fill="x", expand=False, padx=10, pady=8)

        self.log_text = scrolledtext.ScrolledText(
            log_frame, wrap="word", state="disabled", bg="black", fg="#00ff66",
            font=("Consolas", 9), height=12,
        )
        self.log_text.pack(fill="both", expand=True, padx=4, pady=4)

    # ---------------------------------------------------------- Настройки

    def _save_settings(self):
        try:
            port = int(self.port_var.get() or 22)
        except ValueError:
            messagebox.showerror("Ошибка", "Порт должен быть числом.")
            return

        self.cfg = {
            "host": self.host_var.get().strip(),
            "port": port,
            "username": self.user_var.get().strip(),
            "password": self.pass_var.get(),
            "remote_path": self.path_var.get().strip(),
            "router_cheap_api_key": self.api_key_var.get().strip(),
            "router_cheap_model": self.model_var.get().strip(),
        }
        save_config(self.cfg)
        messagebox.showinfo(
            "Готово",
            "Настройки подключения сохранены.\n(логин/пароль хранятся в config.json рядом с приложением)",
        )

    # ---------------------------------------------------------- Лог/вывод

    def _append_log(self, text):
        self.log_text.configure(state="normal")
        self.log_text.insert("end", text)
        self.log_text.see("end")
        self.log_text.configure(state="disabled")

    def _poll_output(self):
        try:
            while True:
                line = self.output_queue.get_nowait()
                if line is None:
                    self._finish_run()
                else:
                    self._append_log(line)
        except queue.Empty:
            pass
        self.root.after(100, self._poll_output)

    def _finish_run(self):
        self.running = False
        self.stop_requested = False
        self.review_btn.configure(state="normal")
        self.apply_btn.configure(state="normal")
        self.indexnow_btn.configure(state="normal")
        self.ocfilter_gen_btn.configure(state="normal")
        self.ocfilter_apply_btn.configure(state="normal")
        self.ocfilter_indexnow_btn.configure(state="normal")
        self.stop_btn.configure(state="disabled")
        self.status_var.set("Готово")

    # ---------------------------------------------------------- Запуск задач

    def _start_review(self):
        section = self.section_var.get().strip()
        if not section:
            messagebox.showerror("Ошибка", "Укажи ID раздела.")
            return
        limit = self.limit_var.get().strip()
        inner_cmd = f"php ai_generate_descriptions_oc.php review {section}"
        if limit:
            inner_cmd += f" {limit}"
        self._start_remote_job("review", section, inner_cmd)

    def _start_apply(self):
        section = self.section_var.get().strip()
        if not section:
            messagebox.showerror("Ошибка", "Укажи ID раздела.")
            return
        inner_cmd = f"php ai_generate_descriptions_oc.php apply {section}"
        self._start_remote_job("apply", section, inner_cmd)

    def _start_remote_job(self, job_type, section, inner_cmd):
        if paramiko is None:
            messagebox.showerror("Ошибка", "Не установлен модуль paramiko (pip install paramiko).")
            return
        if self.running:
            messagebox.showwarning("Внимание", "Уже выполняется другая задача, дождись завершения.")
            return

        host = self.host_var.get().strip()
        try:
            port = int(self.port_var.get() or 22)
        except ValueError:
            messagebox.showerror("Ошибка", "Порт должен быть числом.")
            return
        user = self.user_var.get().strip()
        password = self.pass_var.get()
        remote_path = self.path_var.get().strip()

        if not host or not user or not remote_path:
            messagebox.showerror("Ошибка", "Заполни хост, логин и путь к скрипту в блоке подключения.")
            return

        self.running = True
        self.stop_requested = False
        self.review_btn.configure(state="disabled")
        self.apply_btn.configure(state="disabled")
        self.indexnow_btn.configure(state="disabled")
        self.ocfilter_gen_btn.configure(state="disabled")
        self.ocfilter_apply_btn.configure(state="disabled")
        self.ocfilter_indexnow_btn.configure(state="disabled")
        self.stop_btn.configure(state="normal")
        self.status_var.set("Запускаю на сервере...")
        self._append_log(f"\n$ (в фоне на сервере) {inner_cmd}\n")

        thread = threading.Thread(
            target=self._remote_job_worker,
            args=(
                host, port, user, password, remote_path, job_type, section, inner_cmd,
                self.api_key_var.get().strip(), self.model_var.get().strip(),
            ),
            daemon=True,
        )
        thread.start()

    @staticmethod
    def _connect(host, port, user, password):
        client = paramiko.SSHClient()
        client.set_missing_host_key_policy(paramiko.AutoAddPolicy())
        client.connect(hostname=host, port=port, username=user, password=password, timeout=15)
        client.get_transport().set_keepalive(30)
        return client

    def _remote_job_worker(self, host, port, user, password, remote_path, job_type, section, inner_cmd, api_key="", model=""):
        log_name = f"gui_run_oc_{job_type}_{section}.log"
        pid_name = f"gui_run_oc_{job_type}_{section}.pid"
        log_path = f"{remote_path}/{log_name}"
        pid_path = f"{remote_path}/{pid_name}"

        # Ключ и модель передаются как переменные окружения перед командой —
        # безопасно экранируем их на случай спецсимволов (shlex.quote даёт
        # корректную POSIX-shell-экранировку). PHP-скрипт читает их через
        # getenv(), если они заданы — иначе использует значения по умолчанию,
        # зашитые в самом файле на сервере.
        env_parts = []
        if api_key:
            env_parts.append(f"ROUTER_CHEAP_API_KEY={shlex.quote(api_key)}")
        if model:
            env_parts.append(f"ROUTER_CHEAP_MODEL={shlex.quote(model)}")
        env_prefix = (" ".join(env_parts) + " ") if env_parts else ""

        # Запуск в фоне на сервере: вывод команды идёт в файл лога, PID
        # процесса — в отдельный файл. Возврата самой команды запуска ждём
        # быстро — запущенный процесс продолжает работать независимо от
        # того, жив ли ещё этот конкретный SSH-канал. Отдельная вложенная
        # 'sh -c' здесь не нужна — SSH-сервер и так выполняет команду через
        # шелл пользователя, а лишняя вложенность только усложняла бы
        # экранирование ключа с спецсимволами.
        start_cmd = (
            f"cd {remote_path} && rm -f {log_name} {pid_name} && "
            f"{env_prefix}{inner_cmd} > {log_name} 2>&1 & echo $! > {pid_name}"
        )

        client = None
        try:
            client = self._connect(host, port, user, password)
            self.ssh_client = client

            _, stdout, stderr = client.exec_command(start_cmd)
            stdout.channel.recv_exit_status()
            err_text = stderr.read().decode("utf-8", errors="replace").strip()
            if err_text:
                self.output_queue.put(f"[предупреждение при запуске]: {err_text}\n")

            self.output_queue.put("Задача запущена на сервере в фоне. Слежу за логом...\n\n")

            offset = 0

            while True:
                if self.stop_requested:
                    self.output_queue.put(
                        "\n[Слежение остановлено. Процесс на сервере, скорее всего, ещё работает в "
                        "фоне — снова нажми 'Сгенерировать'/'Записать', чтобы продолжить наблюдение.]\n"
                    )
                    break

                try:
                    if client is None or client.get_transport() is None or not client.get_transport().is_active():
                        client = self._connect(host, port, user, password)
                        self.ssh_client = client

                    tail_cmd = f"tail -c +{offset + 1} {log_path} 2>/dev/null"
                    _, out, _ = client.exec_command(tail_cmd)
                    new_data = out.read()
                    if new_data:
                        offset += len(new_data)
                        self.output_queue.put(new_data.decode("utf-8", errors="replace"))

                    status_cmd = (
                        f"kill -0 $(cat {pid_path} 2>/dev/null) 2>/dev/null && echo RUNNING || echo DONE"
                    )
                    _, out2, _ = client.exec_command(status_cmd)
                    status = out2.read().decode("utf-8", errors="replace").strip()

                    if status == "DONE":
                        # Добираем то, что могло появиться в логе между
                        # последней проверкой и фактическим завершением.
                        _, out3, _ = client.exec_command(tail_cmd)
                        tail_more = out3.read()
                        if tail_more:
                            self.output_queue.put(tail_more.decode("utf-8", errors="replace"))
                        self.output_queue.put("\n--- Задача на сервере завершена ---\n")
                        break

                except Exception as poll_err:
                    self.output_queue.put(
                        f"\n[временная проблема связи: {poll_err} — переподключаюсь через "
                        f"{POLL_INTERVAL_SEC} сек]\n"
                    )
                    try:
                        if client:
                            client.close()
                    except Exception:
                        pass
                    client = None
                    self.ssh_client = None

                time.sleep(POLL_INTERVAL_SEC)

        except Exception as e:
            self.output_queue.put(f"\nОШИБКА: {e}\n")
        finally:
            try:
                if client:
                    client.close()
            except Exception:
                pass
            self.ssh_client = None
            self.output_queue.put(None)

    def _start_indexnow(self):
        section = self.section_var.get().strip()
        if not section:
            messagebox.showerror("Ошибка", "Укажи ID раздела.")
            return
        inner_cmd = f"php ai_generate_descriptions_oc.php indexnow {section}"
        self._start_remote_job("indexnow", section, inner_cmd)

    def _check_count(self):
        section = self.section_var.get().strip()
        if not section:
            messagebox.showerror("Ошибка", "Укажи ID раздела.")
            return

        host = self.host_var.get().strip()
        try:
            port = int(self.port_var.get() or 22)
        except ValueError:
            messagebox.showerror("Ошибка", "Порт должен быть числом.")
            return
        user = self.user_var.get().strip()
        password = self.pass_var.get()
        remote_path = self.path_var.get().strip()

        if not host or not user or not remote_path:
            messagebox.showerror("Ошибка", "Заполни хост, логин и путь к скрипту в блоке подключения.")
            return

        self.check_btn.configure(state="disabled")
        thread = threading.Thread(
            target=self._check_count_worker,
            args=(host, port, user, password, remote_path, section),
            daemon=True,
        )
        thread.start()

    def _check_count_worker(self, host, port, user, password, remote_path, section):
        cmd = f"cd {remote_path} && php ai_generate_descriptions_oc.php count {section}"
        client = None
        try:
            client = self._connect(host, port, user, password)
            _, out, err = client.exec_command(cmd)
            out.channel.recv_exit_status()
            result = out.read().decode("utf-8", errors="replace")
            err_text = err.read().decode("utf-8", errors="replace").strip()
            self.output_queue.put(f"\n[Проверка]{result}")
            if err_text:
                self.output_queue.put(f"[Проверка, доп. вывод]: {err_text}\n")
        except Exception as e:
            self.output_queue.put(f"\n[Проверка] ОШИБКА: {e}\n")
        finally:
            try:
                if client:
                    client.close()
            except Exception:
                pass
            self.root.after(0, lambda: self.check_btn.configure(state="normal"))

    def _stop(self):
        self.stop_requested = True
        self._append_log("\n[Останавливаю слежение за логом...]\n")

    def _on_close(self):
        self.stop_requested = True
        if self.ssh_client:
            try:
                self.ssh_client.close()
            except Exception:
                pass
        self.root.destroy()


    # ---------------------------------------------------------- Редактор промптов

    def _delete_report(self):
        section = self.section_var.get().strip()
        if not section:
            messagebox.showerror("Ошибка", "Укажи ID раздела.")
            return

        host = self.host_var.get().strip()
        try:
            port = int(self.port_var.get() or 22)
        except ValueError:
            messagebox.showerror("Ошибка", "Порт должен быть числом.")
            return
        user = self.user_var.get().strip()
        password = self.pass_var.get()
        remote_path = self.path_var.get().strip()

        if not host or not user or not remote_path:
            messagebox.showerror("Ошибка", "Заполни хост, логин и путь к скрипту в блоке подключения.")
            return

        if not messagebox.askyesno(
            "Подтверждение",
            f"Удалить файл отчёта для раздела {section} на сервере?\n\n"
            "Это НЕ трогает уже записанные в Битрикс тексты (DETAIL_TEXT товаров), "
            "но сотрёт локальную историю генерации — при следующем запуске "
            "'Сгенерировать описания' все товары раздела будут обработаны заново "
            "и уже сгенерированные тексты будут перезаписаны новыми.\n\n"
            "Продолжить?",
        ):
            return

        client = None
        try:
            client = self._connect(host, port, user, password)
            report_file = f"{remote_path}/ai_desc_report_section_{section}.json"
            cmd = f"rm -f {report_file}"
            _, out, err = client.exec_command(cmd)
            out.channel.recv_exit_status()
            err_text = err.read().decode("utf-8", errors="replace").strip()
            if err_text:
                messagebox.showerror("Ошибка", f"Не удалось удалить файл: {err_text}")
            else:
                messagebox.showinfo(
                    "Готово",
                    f"Файл отчёта для раздела {section} удалён.\n"
                    "Следующий запуск 'Сгенерировать описания' начнёт раздел с нуля.",
                )
        except Exception as e:
            messagebox.showerror("Ошибка", f"Не удалось подключиться: {e}")
        finally:
            try:
                if client:
                    client.close()
            except Exception:
                pass

    def _open_prompt_editor(self):
        host = self.host_var.get().strip()
        try:
            port = int(self.port_var.get() or 22)
        except ValueError:
            messagebox.showerror("Ошибка", "Порт должен быть числом.")
            return
        user = self.user_var.get().strip()
        password = self.pass_var.get()
        remote_path = self.path_var.get().strip()

        if not host or not user or not remote_path:
            messagebox.showerror("Ошибка", "Заполни хост, логин и путь к скрипту в блоке подключения.")
            return

        section = self.section_var.get().strip()
        ocfilter_batch = self.ocfilter_batch_var.get().strip()
        PromptEditorWindow(self, host, port, user, password, remote_path, section, ocfilter_batch)

    def _view_products_report(self):
        section = self.section_var.get().strip()
        if not section:
            messagebox.showerror("Ошибка", "Укажи ID раздела.")
            return
        conn = self._get_conn_params_or_error()
        if not conn:
            return
        host, port, user, password, remote_path = conn
        filename = f"ai_desc_report_section_{section}.json"
        ReportViewerWindow(self, host, port, user, password, remote_path, filename)

    def _view_ocfilter_report(self):
        batch = self.ocfilter_batch_var.get().strip()
        if not batch:
            messagebox.showerror("Ошибка", "Укажи ID партии.")
            return
        conn = self._get_conn_params_or_error()
        if not conn:
            return
        host, port, user, password, remote_path = conn
        filename = f"ai_ocfilter_report_batch_{batch}.json"
        ReportViewerWindow(self, host, port, user, password, remote_path, filename)

    def _open_ready_ids_window(self, kind):
        """kind: "products" — список КАТЕГОРИЙ (ID и название), для которых
        на сервере уже есть готовые тексты товаров (сканирует все отчёты
        сразу, не привязано к полю "ID раздела"). "ocfilter" — список
        ПАРТИЙ SEO-страниц с готовыми текстами (тоже сканирует все отчёты
        сразу, не привязано к полю "ID партии")."""
        conn = self._get_conn_params_or_error()
        if not conn:
            return
        host, port, user, password, remote_path = conn
        ReadyIdsWindow(self, host, port, user, password, remote_path, kind)

    # ---------------------------------------------------------- Общие мелочи

    def _get_conn_params_or_error(self):
        """Возвращает (host, port, user, password, remote_path) или None,
        если что-то не заполнено (и сама покажет messagebox с ошибкой)."""
        host = self.host_var.get().strip()
        try:
            port = int(self.port_var.get() or 22)
        except ValueError:
            messagebox.showerror("Ошибка", "Порт должен быть числом.")
            return None
        user = self.user_var.get().strip()
        password = self.pass_var.get()
        remote_path = self.path_var.get().strip()

        if not host or not user or not remote_path:
            messagebox.showerror("Ошибка", "Заполни хост, логин и путь к скрипту в блоке подключения.")
            return None
        return host, port, user, password, remote_path

    # ---------------------------------------------------------- Вкладка "Создание SEO-страниц" (OCFilter)

    def _build_ocfilter_tab(self, parent):
        # Поля и кнопки — сразу сверху, без прокрутки мимо длинного текста;
        # пояснения по формату и сам список страниц — ниже, внизу вкладки.
        id_frame = ttk.Frame(parent)
        id_frame.pack(fill="x", pady=(8, 6))
        ttk.Label(id_frame, text="ID партии (для файлов отчёта):").pack(side="left")
        self.ocfilter_batch_var = tk.StringVar()
        ttk.Entry(id_frame, textvariable=self.ocfilter_batch_var, width=12).pack(side="left", padx=6)

        ttk.Label(id_frame, text="Лимит страниц за прогон (необязательно):").pack(side="left", padx=(16, 0))
        self.ocfilter_limit_var = tk.StringVar()
        ttk.Entry(id_frame, textvariable=self.ocfilter_limit_var, width=8).pack(side="left", padx=6)

        btn_frame = ttk.Frame(parent)
        btn_frame.pack(pady=(0, 4))

        self.ocfilter_gen_btn = ttk.Button(
            btn_frame, text="Сгенерировать SEO-страницы", command=self._start_ocfilter_gen
        )
        self.ocfilter_gen_btn.pack(side="left", padx=4)

        self.ocfilter_apply_btn = ttk.Button(
            btn_frame, text="Записать на сайт (apply)", command=self._start_ocfilter_apply
        )
        self.ocfilter_apply_btn.pack(side="left", padx=4)

        self.ocfilter_check_btn = ttk.Button(
            btn_frame, text="Проверить кол-во", command=self._ocfilter_check_count
        )
        self.ocfilter_check_btn.pack(side="left", padx=4)

        btn_frame2 = ttk.Frame(parent)
        btn_frame2.pack(pady=(0, 8))

        # Отдельная РУЧНАЯ кнопка IndexNow — намеренно не вызывается сама
        # после apply, т.к. модуль ещё тестируется и страницы не должны
        # автоматически улетать в IndexNow при каждой записи на сайт.
        self.ocfilter_indexnow_btn = ttk.Button(
            btn_frame2, text="Отправить в IndexNow", command=self._start_ocfilter_indexnow
        )
        self.ocfilter_indexnow_btn.pack(side="left", padx=4)

        self.ocfilter_edit_prompt_btn = ttk.Button(
            btn_frame2, text="Редактировать промпт SEO-страниц", command=self._open_ocfilter_prompt_editor
        )
        self.ocfilter_edit_prompt_btn.pack(side="left", padx=4)

        self.ocfilter_delete_report_btn = ttk.Button(
            btn_frame2, text="Удалить текущий отчёт (для полной перегенерации)",
            command=self._delete_ocfilter_report,
        )
        self.ocfilter_delete_report_btn.pack(side="left", padx=4)

        self.ocfilter_view_report_btn = ttk.Button(
            btn_frame2, text="Посмотреть тексты", command=self._view_ocfilter_report,
        )
        self.ocfilter_view_report_btn.pack(side="left", padx=4)

        self.ocfilter_ready_ids_btn = ttk.Button(
            btn_frame2, text="Просмотр ID SEO-страниц с готовыми текстами",
            command=lambda: self._open_ready_ids_window("ocfilter"),
        )
        self.ocfilter_ready_ids_btn.pack(side="left", padx=4)

        list_frame = ttk.LabelFrame(parent, text="Список SEO-страниц (одна строка — одна страница)")
        list_frame.pack(fill="both", expand=True, pady=(4, 4))
        self.ocfilter_urls_text = scrolledtext.ScrolledText(list_frame, wrap="none", height=8, font=("Consolas", 9))
        self.ocfilter_urls_text.pack(fill="both", expand=True, padx=4, pady=4)
        # Copy/paste/select-all по физическому коду клавиши — без этого
        # Ctrl+C/V/A может не срабатывать при русской раскладке клавиатуры
        # (та же функция уже используется в редакторе промптов).
        setup_text_editing_shortcuts(self.ocfilter_urls_text)

        info = (
            "Можно вставлять прямо готовые ссылки на страницы (по одной в строке) —\n"
            "категория, KEYWORD и параметры фильтра определятся из адреса автоматически:\n"
            "   https://fe-rus.ru/list-stalnoj-gladkij/marka/r6m5/\n"
            "   https://fe-rus.ru/list-stalnoj-gladkij/marka/s355-5/\n"
            "\n"
            "Либо (если нужен другой адрес страницы, чем в ссылке) — явный формат\n"
            "KEYWORD;CATEGORY;PARAMS:\n"
            "   KEYWORD  — желаемый адрес страницы, напр. armatura-stal-09g2s\n"
            "   CATEGORY — ID категории (число) или её адрес (слаг) в URL сайта\n"
            "   PARAMS   — параметры фильтра из адреса, напр. marka/09g2s\n"
            "Пример:\n"
            "   armatura-stal-09g2s;121;marka/09g2s\n"
            "   shina-med-m1-shmm;145;marka/m1/tip/shmm"
        )
        ttk.Label(parent, text=info, justify="left", foreground="#555555").pack(
            anchor="w", padx=0, pady=(0, 8)
        )

    def _upload_ocfilter_urls(self, conn, batch):
        """Заливает содержимое текстового поля на сервер в
        ocfilter_urls_batch_{batch}.txt через SFTP — PHP-скрипт читает
        именно этот файл при запуске genocfilter."""
        host, port, user, password, remote_path = conn
        content = self.ocfilter_urls_text.get("1.0", "end-1c")
        client = self._connect(host, port, user, password)
        try:
            sftp = client.open_sftp()
            remote_file = f"{remote_path}/ocfilter_urls_batch_{batch}.txt"
            with sftp.open(remote_file, "w") as f:
                f.write(content.encode("utf-8"))
            sftp.close()
        finally:
            client.close()

    def _start_ocfilter_gen(self):
        batch = self.ocfilter_batch_var.get().strip()
        if not batch:
            messagebox.showerror("Ошибка", "Укажи ID партии.")
            return
        if not self.ocfilter_urls_text.get("1.0", "end-1c").strip():
            messagebox.showerror("Ошибка", "Список SEO-страниц пуст.")
            return

        conn = self._get_conn_params_or_error()
        if not conn:
            return

        try:
            self._upload_ocfilter_urls(conn, batch)
        except Exception as e:
            messagebox.showerror("Ошибка", f"Не удалось загрузить список страниц на сервер: {e}")
            return

        limit = self.ocfilter_limit_var.get().strip()
        inner_cmd = f"php ai_generate_descriptions_oc.php genocfilter {batch}"
        if limit:
            inner_cmd += f" {limit}"
        self._start_remote_job("genocfilter", batch, inner_cmd)

    def _start_ocfilter_apply(self):
        batch = self.ocfilter_batch_var.get().strip()
        if not batch:
            messagebox.showerror("Ошибка", "Укажи ID партии.")
            return
        inner_cmd = f"php ai_generate_descriptions_oc.php applyocfilter {batch}"
        self._start_remote_job("applyocfilter", batch, inner_cmd)

    def _start_ocfilter_indexnow(self):
        batch = self.ocfilter_batch_var.get().strip()
        if not batch:
            messagebox.showerror("Ошибка", "Укажи ID партии.")
            return
        inner_cmd = f"php ai_generate_descriptions_oc.php indexnowocfilter {batch}"
        self._start_remote_job("indexnowocfilter", batch, inner_cmd)

    def _ocfilter_check_count(self):
        batch = self.ocfilter_batch_var.get().strip()
        if not batch:
            messagebox.showerror("Ошибка", "Укажи ID партии.")
            return
        conn = self._get_conn_params_or_error()
        if not conn:
            return
        self.ocfilter_check_btn.configure(state="disabled")
        thread = threading.Thread(
            target=self._ocfilter_check_count_worker, args=(conn, batch), daemon=True
        )
        thread.start()

    def _ocfilter_check_count_worker(self, conn, batch):
        host, port, user, password, remote_path = conn
        cmd = f"cd {remote_path} && php ai_generate_descriptions_oc.php countocfilter {batch}"
        client = None
        try:
            client = self._connect(host, port, user, password)
            _, out, err = client.exec_command(cmd)
            out.channel.recv_exit_status()
            result = out.read().decode("utf-8", errors="replace")
            err_text = err.read().decode("utf-8", errors="replace").strip()
            self.output_queue.put(f"\n[SEO-страницы, проверка]{result}")
            if err_text:
                self.output_queue.put(f"[проверка, доп. вывод]: {err_text}\n")
        except Exception as e:
            self.output_queue.put(f"\n[SEO-страницы, проверка] ОШИБКА: {e}\n")
        finally:
            try:
                if client:
                    client.close()
            except Exception:
                pass
            self.root.after(0, lambda: self.ocfilter_check_btn.configure(state="normal"))

    def _delete_ocfilter_report(self):
        batch = self.ocfilter_batch_var.get().strip()
        if not batch:
            messagebox.showerror("Ошибка", "Укажи ID партии.")
            return
        conn = self._get_conn_params_or_error()
        if not conn:
            return
        host, port, user, password, remote_path = conn

        if not messagebox.askyesno(
            "Подтверждение",
            f"Удалить файл отчёта для партии {batch} на сервере?\n\n"
            "Это НЕ трогает уже записанные на сайт SEO-страницы, но сотрёт "
            "локальную историю генерации — при следующем запуске 'Сгенерировать "
            "SEO-страницы' весь список будет обработан заново.\n\n"
            "Продолжить?",
        ):
            return

        client = None
        try:
            client = self._connect(host, port, user, password)
            report_file = f"{remote_path}/ai_ocfilter_report_batch_{batch}.json"
            cmd = f"rm -f {report_file}"
            _, out, err = client.exec_command(cmd)
            out.channel.recv_exit_status()
            err_text = err.read().decode("utf-8", errors="replace").strip()
            if err_text:
                messagebox.showerror("Ошибка", f"Не удалось удалить файл: {err_text}")
            else:
                messagebox.showinfo("Готово", f"Файл отчёта для партии {batch} удалён.")
        except Exception as e:
            messagebox.showerror("Ошибка", f"Не удалось подключиться: {e}")
        finally:
            try:
                if client:
                    client.close()
            except Exception:
                pass

    def _open_ocfilter_prompt_editor(self):
        # Та же самая функциональность, что и у кнопки "Редактировать
        # промпт" на вкладке товаров - одно общее окно с двумя вкладками
        # (товарные промпты / промпты SEO-страниц), просто открытое с
        # другой кнопки. Отдельного окна только для OCFilter больше нет.
        self._open_prompt_editor()


class ReportViewerWindow(tk.Toplevel):
    """Окно "Посмотреть тексты" - скачивает JSON-отчёт (ai_desc_report_section_*.json
    для товаров или ai_ocfilter_report_batch_*.json для SEO-страниц) с сервера по
    SFTP и показывает его в читаемом (pretty-printed) виде, без необходимости лезть
    на сервер через SSH руками."""

    def __init__(self, app, host, port, user, password, remote_path, filename):
        super().__init__(app.root)
        self.app = app
        self.host, self.port, self.user, self.password = host, port, user, password
        self.remote_path, self.filename = remote_path, filename

        self.title(f"Тексты: {filename}")
        self.geometry("900x650")

        toolbar = ttk.Frame(self)
        toolbar.pack(fill="x", padx=6, pady=(6, 0))
        ttk.Label(toolbar, text=filename, foreground="#555555").pack(side="left")
        self.status_label = ttk.Label(toolbar, text="")
        self.status_label.pack(side="left", padx=10)
        ttk.Button(toolbar, text="Обновить", command=self._load).pack(side="right")

        self.text = scrolledtext.ScrolledText(self, wrap="word", font=("Consolas", 9))
        self.text.pack(fill="both", expand=True, padx=6, pady=6)
        # Копировать содержимое отчёта нужно постоянно - те же
        # Ctrl+C/V/A по физическому коду клавиши, что и везде в программе.
        setup_text_editing_shortcuts(self.text)

        self._load()

    def _load(self):
        self.status_label.configure(text="Загружаю...")
        self.text.configure(state="normal")
        self.text.delete("1.0", "end")
        thread = threading.Thread(target=self._load_worker, daemon=True)
        thread.start()

    def _load_worker(self):
        remote_file = f"{self.remote_path}/{self.filename}"
        client = None
        try:
            client = App._connect(self.host, self.port, self.user, self.password)
            sftp = client.open_sftp()
            try:
                with sftp.open(remote_file, "r") as f:
                    raw = f.read()
            finally:
                sftp.close()
            if isinstance(raw, bytes):
                raw = raw.decode("utf-8", errors="replace")
            try:
                parsed = json.loads(raw)
                content = json.dumps(parsed, ensure_ascii=False, indent=2)
                count = len(parsed) if isinstance(parsed, list) else None
                status = f"Записей: {count}" if count is not None else "Загружено"
            except Exception:
                content = raw
                status = "Загружено (не JSON или повреждён)"
            self.after(0, lambda: self._show(content, status))
        except FileNotFoundError:
            self.after(0, lambda: self._show(
                f"Файл отчёта не найден на сервере:\n{remote_file}\n\n"
                "Он появится после первого запуска генерации для этого ID.",
                "Файл не найден",
            ))
        except Exception as e:
            self.after(0, lambda: self._show(f"Ошибка загрузки: {e}", "Ошибка"))
        finally:
            try:
                if client:
                    client.close()
            except Exception:
                pass

    def _show(self, content, status):
        self.status_label.configure(text=status)
        self.text.delete("1.0", "end")
        self.text.insert("end", content)


class ReadyIdsWindow(tk.Toplevel):
    """Отдельное окно со списком того, для чего уже есть готовый
    сгенерированный текст (запись в отчёте на сервере со статусом
    'approved'/'applied' для товаров или 'generated'/'applied' для
    SEO-страниц OCFilter). Для "products" — список КАТЕГОРИЙ в формате
    "ID - название категории", отсортировано по возрастанию ID: сканирует
    ВСЕ файлы ai_desc_report_section_*.json на сервере разом, не только
    текущее значение поля "ID раздела". Для "ocfilter" — список ПАРТИЙ SEO-
    страниц в формате "ID партии - название(я) категории (готово: N)",
    тоже сканирует все ai_ocfilter_report_batch_*.json разом."""

    def __init__(self, app, host, port, user, password, remote_path, kind):
        super().__init__(app.root)
        self.app = app
        self.host, self.port, self.user, self.password = host, port, user, password
        self.remote_path, self.kind = remote_path, kind

        if kind == "products":
            self.title("Категории с готовыми текстами товаров")
            label_text = "Все категории с готовыми текстами товаров, отсортировано по возрастанию ID:"
        else:
            self.title("Партии SEO-страниц с готовыми текстами")
            label_text = "Все партии SEO-страниц с готовыми текстами, отсортировано по возрастанию ID партии:"
        self.geometry("560x560")

        ttk.Label(self, text=label_text, foreground="#555555", wraplength=540).pack(
            anchor="w", padx=10, pady=(10, 4)
        )

        list_frame = ttk.Frame(self)
        list_frame.pack(fill="both", expand=True, padx=10, pady=(0, 8))

        scrollbar = ttk.Scrollbar(list_frame)
        scrollbar.pack(side="right", fill="y")

        self.listbox = tk.Listbox(list_frame, font=("Consolas", 10), yscrollcommand=scrollbar.set)
        self.listbox.pack(side="left", fill="both", expand=True)
        scrollbar.config(command=self.listbox.yview)

        self.status_var = tk.StringVar(value="Загружаю...")
        ttk.Label(self, textvariable=self.status_var, foreground="blue").pack(anchor="w", padx=10, pady=(0, 10))

        self._load()

    def _load(self):
        thread = threading.Thread(target=self._load_worker, daemon=True)
        thread.start()

    def _load_worker(self):
        cmd_name = "listreadyids" if self.kind == "products" else "listreadyocfilterids"
        cmd = f"cd {self.remote_path} && php ai_generate_descriptions_oc.php {cmd_name}"
        client = None
        try:
            client = App._connect(self.host, self.port, self.user, self.password)
            _, out, err = client.exec_command(cmd)
            out.channel.recv_exit_status()
            result = out.read().decode("utf-8", errors="replace")
            err_text = err.read().decode("utf-8", errors="replace").strip()

            lines = [line.strip() for line in result.splitlines() if line.strip()]
            summary = lines[0] if lines else ""
            items = lines[1:] if len(lines) > 1 else []

            def sort_key(line):
                # Строка вида "384 - Лист стальной гладкий" — сортируем по
                # числу перед " - ", а не по алфавиту всей строки.
                head = line.split(" - ", 1)[0].strip()
                try:
                    return (0, int(head))
                except ValueError:
                    return (1, line)

            items = sorted(items, key=sort_key)

            self.after(0, lambda: self._show_result(summary, items, err_text))
        except Exception as e:
            self.after(0, lambda err=e: self.status_var.set(f"Ошибка: {err}"))
        finally:
            try:
                if client:
                    client.close()
            except Exception:
                pass

    def _show_result(self, summary, items, err_text):
        self.listbox.delete(0, "end")
        for item in items:
            self.listbox.insert("end", item)
        if err_text:
            self.status_var.set(f"{summary}  [предупреждение: {err_text}]")
        else:
            self.status_var.set(summary or f"Найдено: {len(items)}")


class PromptEditorTab(ttk.Frame):
    """Один "экран" редактора промптов — либо товарные промпты, либо
    промпты SEO-страниц OCFilter. Логика идентична для обоих типов,
    отличаются только используемые команды скрипта, папка на сервере и то,
    что подставляется по умолчанию в диалог привязки — поэтому вынесено в
    один переиспользуемый класс с параметрами (так же, как в Bitrix-версии
    ai_desc_gui_1stall.py)."""

    def __init__(self, parent, app, host, port, user, password, remote_path,
                 remote_dir, list_cmd, which_cmd, bind_cmd, hint_text,
                 dir_label, id_label, starter_template, default_id_getter, section=None):
        super().__init__(parent)
        self.app = app
        self.host = host
        self.port = port
        self.user = user
        self.password = password
        self.remote_path = remote_path
        self.remote_dir = remote_dir  # полный путь на сервере к папке с файлами этого типа
        self.list_cmd = list_cmd
        self.which_cmd = which_cmd
        self.bind_cmd = bind_cmd
        self.dir_label_text = dir_label
        self.id_label = id_label
        self.starter_template = starter_template
        self.default_id_getter = default_id_getter

        top_frame = ttk.Frame(self)
        top_frame.pack(fill="x", padx=10, pady=8)

        ttk.Label(top_frame, text="Файл промпта:").pack(side="left")
        self.file_var = tk.StringVar()
        self.file_combo = ttk.Combobox(top_frame, textvariable=self.file_var, width=40, state="readonly")
        self.file_combo.pack(side="left", padx=6)
        self.file_combo.bind("<<ComboboxSelected>>", lambda e: self._load_selected())

        ttk.Button(top_frame, text="Создать новый файл", command=self._create_new_file).pack(side="left", padx=4)
        ttk.Button(
            top_frame, text=f"Привязать к {self.id_label}", command=self._bind_current_to_section
        ).pack(side="left", padx=4)

        self.save_btn = ttk.Button(top_frame, text="Сохранить на сервер", command=self._save_current)
        self.save_btn.pack(side="right", padx=4)

        self.info_var = tk.StringVar(value="")
        ttk.Label(self, textvariable=self.info_var, foreground="blue").pack(anchor="w", padx=12)

        self.text_area = scrolledtext.ScrolledText(self, wrap="word", font=("Consolas", 10))
        self.text_area.pack(fill="both", expand=True, padx=10, pady=8)
        setup_text_editing_shortcuts(self.text_area)

        self.current_file = None

        ttk.Label(self, text=hint_text, wraplength=760, foreground="#555555").pack(anchor="w", padx=12, pady=(0, 8))

        self._refresh_file_list(preselect_for_section=section)

    def _connect(self):
        client = paramiko.SSHClient()
        client.set_missing_host_key_policy(paramiko.AutoAddPolicy())
        client.connect(hostname=self.host, port=self.port, username=self.user, password=self.password, timeout=15)
        # keepalive — некоторые роутеры/файрволы разрывают "неактивные" TCP-
        # соединения, если не видят в них трафика; keepalive шлёт пустые
        # пакеты каждые 30 секунд, чтобы соединение не считалось неактивным.
        transport = client.get_transport()
        if transport:
            transport.set_keepalive(30)
        return client

    def _refresh_file_list(self, preselect_for_section=None):
        client = None
        try:
            client = self._connect()

            cmd = f"cd {self.remote_path} && php ai_generate_descriptions_oc.php {self.list_cmd}"
            _, out, err = client.exec_command(cmd)
            out.channel.recv_exit_status()
            files = [line.strip() for line in out.read().decode("utf-8", errors="replace").splitlines() if line.strip()]

            if not files:
                messagebox.showwarning("Пусто", f"Не найдено файлов промптов в папке {self.dir_label_text}/ на сервере.")
                return

            self.file_combo["values"] = files

            selected = files[0]
            if preselect_for_section:
                cmd2 = f"cd {self.remote_path} && php ai_generate_descriptions_oc.php {self.which_cmd} {preselect_for_section}"
                _, out2, _ = client.exec_command(cmd2)
                out2.channel.recv_exit_status()
                resolved = out2.read().decode("utf-8", errors="replace").strip()
                if resolved in files:
                    selected = resolved

            self.file_var.set(selected)
            self._load_selected()

        except Exception as e:
            messagebox.showerror("Ошибка", f"Не удалось получить список файлов: {e}")
        finally:
            try:
                if client:
                    client.close()
            except Exception:
                pass

    def _bind_current_to_section(self):
        filename = self.file_var.get().strip()
        if not filename:
            messagebox.showerror("Ошибка", "Сначала выбери файл промпта из списка.")
            return

        default_id = self.default_id_getter()
        ids_raw = simpledialog.askstring(
            f"Привязка к {self.id_label}",
            f"К каким {self.id_label} привязать файл {self.dir_label_text}/{filename}?\n"
            "Можно указать несколько через запятую, например: 720, 721, 722",
            initialvalue=default_id,
            parent=self,
        )
        if not ids_raw or not ids_raw.strip():
            return

        raw_parts = ids_raw.replace(";", ",").replace(" ", ",").split(",")
        ids = [p.strip() for p in raw_parts if p.strip()]
        if not ids:
            return

        client = None
        try:
            client = self._connect()
            results = []
            for _id in ids:
                cmd = f"cd {self.remote_path} && php ai_generate_descriptions_oc.php {self.bind_cmd} {_id} {filename}"
                _, out, err = client.exec_command(cmd)
                out.channel.recv_exit_status()
                result = out.read().decode("utf-8", errors="replace").strip()
                results.append(result or f"{_id}: привязка обновлена.")
            messagebox.showinfo("Готово", "\n".join(results))
        except Exception as e:
            messagebox.showerror("Ошибка", f"Не удалось привязать: {e}")
        finally:
            try:
                if client:
                    client.close()
            except Exception:
                pass

    def _create_new_file(self):
        filename = simpledialog.askstring(
            "Новый файл промпта",
            "Имя файла (например: pipes.txt):",
            parent=self,
        )
        if not filename:
            return

        filename = filename.strip()
        if not filename.endswith(".txt"):
            filename += ".txt"

        existing = list(self.file_combo["values"])
        if filename in existing:
            messagebox.showerror("Ошибка", f"Файл {filename} уже существует. Выбери его из списка для редактирования.")
            return

        client = None
        try:
            client = self._connect()
            sftp = client.open_sftp()
            remote_file = f"{self.remote_dir}/{filename}"
            with sftp.open(remote_file, "w") as f:
                f.write(self.starter_template.encode("utf-8"))
            sftp.close()

            # Сразу спрашиваем, к каким ID привязать новый файл — привязка
            # сохраняется на сервере в prompts/section_mapping.json через
            # отдельную команду скрипта (bindprompt/bindocfilterprompt).
            default_id = self.default_id_getter()
            ids_raw = simpledialog.askstring(
                f"Привязка к {self.id_label}",
                f"К каким {self.id_label} привязать этот промпт?\n"
                "Можно указать несколько через запятую, например: 720, 721, 722\n"
                "(оставь пустым, если хочешь привязать позже вручную)",
                initialvalue=default_id,
                parent=self,
            )

            bind_message = ""
            if ids_raw and ids_raw.strip():
                raw_parts = ids_raw.replace(";", ",").replace(" ", ",").split(",")
                ids = [p.strip() for p in raw_parts if p.strip()]
                bind_results = []
                for _id in ids:
                    bind_cmd = f"cd {self.remote_path} && php ai_generate_descriptions_oc.php {self.bind_cmd} {_id} {filename}"
                    _, bind_out, bind_err = client.exec_command(bind_cmd)
                    bind_out.channel.recv_exit_status()
                    bind_result = bind_out.read().decode("utf-8", errors="replace").strip()
                    bind_results.append(bind_result or f"{_id}: привязка обновлена.")
                bind_message = "\n\n" + "\n".join(bind_results)

            messagebox.showinfo(
                "Готово",
                f"Файл {self.dir_label_text}/{filename} создан на сервере.{bind_message}",
            )
            self._refresh_file_list()
            self.file_var.set(filename)
            self._load_selected()
        except Exception as e:
            messagebox.showerror("Ошибка", f"Не удалось создать файл: {e}")
        finally:
            try:
                if client:
                    client.close()
            except Exception:
                pass

    def _load_selected(self):
        filename = self.file_var.get().strip()
        if not filename:
            return

        client = None
        try:
            client = self._connect()
            sftp = client.open_sftp()
            remote_file = f"{self.remote_dir}/{filename}"
            with sftp.open(remote_file, "r") as f:
                content = f.read().decode("utf-8", errors="replace")
            sftp.close()

            self.text_area.delete("1.0", "end")
            self.text_area.insert("1.0", content)
            self.current_file = filename
            self.info_var.set(f"Загружено: {self.dir_label_text}/{filename}")
        except Exception as e:
            messagebox.showerror("Ошибка", f"Не удалось загрузить файл: {e}")
        finally:
            try:
                if client:
                    client.close()
            except Exception:
                pass

    def _save_current(self):
        filename = self.file_var.get().strip()
        if not filename:
            messagebox.showerror("Ошибка", "Файл не выбран.")
            return

        if not messagebox.askyesno(
            "Подтверждение",
            f"Перезаписать файл {self.dir_label_text}/{filename} на сервере?\n"
            "Это сразу повлияет на все следующие запуски генерации.",
        ):
            return

        content = self.text_area.get("1.0", "end-1c")

        client = None
        try:
            client = self._connect()
            sftp = client.open_sftp()
            remote_file = f"{self.remote_dir}/{filename}"
            with sftp.open(remote_file, "w") as f:
                f.write(content.encode("utf-8"))
            sftp.close()
            self.info_var.set(f"Сохранено: {self.dir_label_text}/{filename}")
            messagebox.showinfo("Готово", f"Файл {self.dir_label_text}/{filename} обновлён на сервере.")
        except Exception as e:
            messagebox.showerror("Ошибка", f"Не удалось сохранить файл: {e}")
        finally:
            try:
                if client:
                    client.close()
            except Exception:
                pass


class PromptEditorWindow(tk.Toplevel):
    """Окно редактора промптов с двумя вкладками — товарные промпты и
    промпты SEO-страниц OCFilter. Каждая вкладка работает со своей
    отдельной папкой на сервере и своим набором ID/команд, не пересекаясь.
    Открывается ОДНОЙ и той же функцией с обеих кнопок в главном окне
    ("Редактировать промпт" на вкладке товаров и "Редактировать промпт
    SEO-страниц" на вкладке OCFilter) — кнопки две, окно и функциональность
    одни, как в Bitrix-версии приложения (ai_desc_gui_1stall.py)."""

    def __init__(self, app, host, port, user, password, remote_path, section, ocfilter_batch=None):
        super().__init__(app.root)
        self.title("Редактор промптов")
        self.geometry("820x680")

        product_hint = (
            "Плейсхолдеры, которые скрипт подставит автоматически: "
            "{{NAME}} {{SECTION}} {{PROPS}} {{EXTRA_BLOCK}} {{REWRITE_BLOCK}} "
            "(в rewrite_block.txt также доступен {{EXISTING_TEXT}}). Не удаляй их, если только не уверен."
        )
        ocfilter_hint = (
            "Плейсхолдеры, которые скрипт подставит автоматически: "
            "{{CATEGORY_NAME}} {{PARAMS}} {{KEYWORD}}. Не удаляй их, если только не уверен. "
            "Если файла ещё нет на сервере — при первом запуске 'Сгенерировать SEO-страницы' "
            "будет создан default.txt автоматически со стартовым шаблоном."
        )

        product_starter = (
            "Ты — SEO-копирайтер металлоторговой компании.\n\n"
            "Напиши уникальное описание товара для карточки в интернет-каталоге.\n\n"
            "Товар: {{NAME}}\n"
            "Категория: {{SECTION}}\n"
            "Характеристики:\n"
            "{{PROPS}}\n"
            "{{EXTRA_BLOCK}}{{REWRITE_BLOCK}}\n"
            "Требования к тексту:\n"
            "1. Объём 300-500 слов, HTML-теги <h2>/<h3>/<p> (без markdown).\n"
            "2. Не выдумывай характеристики, которых нет в списке выше.\n"
            "3. Без рекламных клише.\n"
        )
        ocfilter_starter = (
            "Ты — SEO-копирайтер металлоторговой компании.\n\n"
            "Напиши уникальный SEO-текст для страницы фильтра каталога интернет-магазина.\n\n"
            "Категория: {{CATEGORY_NAME}}\n"
            "Параметры фильтра (технические, необязательно понятные без контекста): {{PARAMS}}\n"
            "Желаемый общий смысл страницы (заголовок H1 подбери сам, по-русски, коротко и по-товарному): {{KEYWORD}}\n\n"
            "Требования к тексту:\n"
            "1. Объём 150-300 слов, HTML-теги <p>/<h2> (без markdown, без <h1> — заголовок H1 сайт возьмёт из отдельного поля).\n"
            "2. Не выдумывай характеристики и ГОСТы, которых нет в параметрах фильтра или в названии категории.\n"
            "3. Естественно упомяни город/доставку через плейсхолдеры %CITY%, %PHONE%, %EMAIL% — сайт сам их заменит.\n"
            "4. Без рекламных клише, пиши по-деловому.\n"
        )

        notebook = ttk.Notebook(self)
        notebook.pack(fill="both", expand=True, padx=6, pady=6)

        product_tab = PromptEditorTab(
            notebook, app, host, port, user, password, remote_path,
            remote_dir=f"{remote_path}/prompts",
            list_cmd="listprompts",
            which_cmd="whichprompt",
            bind_cmd="bindprompt",
            hint_text=product_hint,
            dir_label="prompts",
            id_label="ID категории(-ий)",
            starter_template=product_starter,
            default_id_getter=lambda: app.section_var.get().strip(),
            section=section,
        )
        notebook.add(product_tab, text="Промпт для текстов товаров")

        ocfilter_tab = PromptEditorTab(
            notebook, app, host, port, user, password, remote_path,
            remote_dir=f"{remote_path}/prompts/ocfilter",
            list_cmd="listocfilterprompts",
            which_cmd="whichocfilterprompt",
            bind_cmd="bindocfilterprompt",
            hint_text=ocfilter_hint,
            dir_label="prompts/ocfilter",
            id_label="ID партии(-ий)",
            starter_template=ocfilter_starter,
            default_id_getter=lambda: app.ocfilter_batch_var.get().strip(),
            section=ocfilter_batch,
        )
        notebook.add(ocfilter_tab, text="Промпт для SEO страниц")


if __name__ == "__main__":
    root = tk.Tk()
    app = App(root)
    root.mainloop()