#!/usr/bin/env python3
"""Disposable MySQL classic-protocol relay that holds one wp_postmeta UPDATE
packet after PHP sent it and releases it AFTER the PHP process is SIGKILLed.
This reproduces in-network SQL that has not yet acquired any InnoDB row lock.
Use only inside GitHub Actions with a disposable WordPress/MySQL service.
"""
import os
import socket
import socketserver
import sys
import threading
import time

TAG = b"ESP67_DELAY_SQL_PACKET"
ROOT = sys.argv[2]
os.makedirs(ROOT, exist_ok=True)
RECEIVED = os.path.join(ROOT, "packet-received")
RELEASE = os.path.join(ROOT, "packet-release")
DONE = os.path.join(ROOT, "packet-done")
for f in (RECEIVED, RELEASE, DONE):
    try:
        os.unlink(f)
    except FileNotFoundError:
        pass

def read_exact(conn, length):
    out = b""
    while len(out) < length:
        data = conn.recv(length - len(out))
        if not data:
            raise EOFError("peer closed")
        out += data
    return out

def packet(conn):
    header = read_exact(conn, 4)
    return header + read_exact(conn, int.from_bytes(header[:3], "little"))

class Handler(socketserver.BaseRequestHandler):
    def handle(self):
        server = socket.create_connection(("127.0.0.1", 3306), timeout=30)
        client = self.request
        finished = threading.Event()
        forwarded = threading.Event()

        def to_server():
            try:
                while not finished.is_set():
                    data = packet(client)
                    if len(data) > 5 and data[4] == 3 and TAG in data:
                        with open(RECEIVED, "w") as f:
                            f.write("accepted")
                        print("ESP67_PROXY_SQL_HELD_NOT_SENT", flush=True)
                        until = time.monotonic() + 25
                        while not os.path.exists(RELEASE) and time.monotonic() < until:
                            time.sleep(0.03)
                        if not os.path.exists(RELEASE):
                            raise RuntimeError("SQL release marker timed out")
                        print("ESP67_PROXY_SQL_FORWARDED_AFTER_PHP_DEATH", flush=True)
                        forwarded.set()
                    server.sendall(data)
            except (EOFError, OSError):
                pass
            except Exception as e:
                print("ESP67_PROXY_SEND_FAILURE", repr(e), flush=True)
            finally:
                finished.set()

        t = threading.Thread(target=to_server, daemon=True)
        t.start()
        try:
            while not finished.is_set():
                data = packet(server)
                if forwarded.is_set() and len(data) > 4:
                    with open(DONE, "w") as f:
                        f.write(str(data[4]))
                    print("ESP67_PROXY_LATE_SERVER_REPLY", data[4], flush=True)
                    forwarded.clear()
                try:
                    client.sendall(data)
                except OSError:
                    # A dead PHP worker must not cause us to retract its
                    # already accepted, delayed SQL from the MySQL server.
                    pass
        except (EOFError, OSError):
            pass
        finally:
            finished.set()
            for conn in (client, server):
                try:
                    conn.close()
                except OSError:
                    pass
            t.join(timeout=1)

class Server(socketserver.ThreadingTCPServer):
    allow_reuse_address = True
    daemon_threads = True

with Server(("0.0.0.0", int(sys.argv[1])), Handler) as server:
    print("ESP67_PROXY_READY", flush=True)
    server.serve_forever()
