import os
import socket
from pathlib import Path
from typing import Optional

from fastapi import FastAPI, HTTPException
from pydantic import BaseModel


ADMIN_SOCKET = os.environ.get("ADMIN_SOCKET", "/var/run/haproxy/admin.sock")
RELOAD_FLAG = os.environ.get("RELOAD_FLAG", "/var/run/haproxy/reload.flag")
API_TOKEN = os.environ.get("API_TOKEN")

app = FastAPI(title="HAProxy Runtime API", version="1.0.0")


class AuthRequest(BaseModel):
    token: Optional[str] = None


class CommandRequest(AuthRequest):
    command: str


def _check_token(token: Optional[str]) -> None:
    if API_TOKEN and token != API_TOKEN:
        raise HTTPException(status_code=403, detail="Invalid token")


def _run_haproxy_command(command: str) -> str:
    if not os.path.exists(ADMIN_SOCKET):
        raise HTTPException(status_code=503, detail="HAProxy socket unavailable")

    client = socket.socket(socket.AF_UNIX, socket.SOCK_STREAM)
    client.settimeout(2)
    try:
        client.connect(ADMIN_SOCKET)
        client.sendall((command.strip() + "\n").encode("utf-8"))
        chunks = []
        while True:
            try:
                data = client.recv(4096)
            except socket.timeout:
                break
            if not data:
                break
            chunks.append(data.decode("utf-8", errors="ignore"))
    finally:
        client.close()

    return "".join(chunks).strip()


def _touch_reload_flag() -> None:
    path = Path(RELOAD_FLAG)
    path.parent.mkdir(parents=True, exist_ok=True)
    try:
        path.write_text("reload\n", encoding="utf-8")
    except OSError as exc:
        raise HTTPException(status_code=500, detail=f"Unable to write reload flag: {exc}") from exc


@app.get("/health")
def health() -> dict:
    return {
        "admin_socket": os.path.exists(ADMIN_SOCKET),
        "reload_flag": os.path.exists(RELOAD_FLAG),
        "success": True,
    }


@app.post("/execute")
def execute(req: CommandRequest) -> dict:
    _check_token(req.token)
    output = _run_haproxy_command(req.command)
    return {"success": True, "output": output}


@app.post("/reload")
def trigger_reload(req: AuthRequest) -> dict:
    _check_token(req.token)
    _touch_reload_flag()
    return {"success": True}
