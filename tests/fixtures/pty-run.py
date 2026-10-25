"""Runs a command on a fresh pseudo-terminal for the CLI prompt tests.

Usage: python3 pty-run.py SPEC_JSON, where SPEC is
{"cmd": [...], "cwd": "...", "steps": [["wait", TEXT] | ["send", TEXT] | ["sigint"]]}.
The command gets the pty as stdin, stdout, stderr and controlling terminal,
so Ctrl-C ("sigint", sent as the ^C character) reaches it as from a
keyboard. Prints JSON: everything the terminal showed, the exit code (a
negative number for a signal), and `stty -a` of the pty after the command
has exited.
"""
import fcntl
import json
import os
import select
import subprocess
import sys
import termios
import time

spec = json.loads(sys.argv[1])
master, slave = os.openpty()
attrs = termios.tcgetattr(slave)
attrs[3] |= termios.ECHO | termios.ICANON | termios.ISIG
termios.tcsetattr(slave, termios.TCSANOW, attrs)


def controlling_tty():
    os.setsid()
    fcntl.ioctl(0, termios.TIOCSCTTY, 0)


proc = subprocess.Popen(spec["cmd"], stdin=slave, stdout=slave, stderr=slave,
                        cwd=spec["cwd"], preexec_fn=controlling_tty, close_fds=True)
shown = b""


def pump(seconds):
    global shown
    ready, _, _ = select.select([master], [], [], seconds)
    if ready:
        try:
            shown += os.read(master, 4096)
        except OSError:
            pass


for step in spec["steps"]:
    if step[0] == "wait":
        deadline = time.time() + 60
        while step[1].encode() not in shown:
            if time.time() > deadline or proc.poll() is not None and not select.select([master], [], [], 0.5)[0]:
                break
            pump(0.2)
    elif step[0] == "send":
        os.write(master, step[1].encode())
    elif step[0] == "sigint":
        os.write(master, b"\x03")

try:
    code = proc.wait(timeout=60)
except subprocess.TimeoutExpired:
    proc.kill()
    code = proc.wait()
pump(0.2)
while select.select([master], [], [], 0.2)[0]:
    before = len(shown)
    pump(0)
    if len(shown) == before:
        break
stty = subprocess.run(["stty", "-a"], stdin=slave, capture_output=True, text=True).stdout
print(json.dumps({"shown": shown.decode("utf-8", "replace"), "exit": code, "stty": stty}))
