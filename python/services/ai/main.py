#!/usr/bin/env python3
"""
LaraPython AI Microservice Demo.
Supports both FastAPI + Uvicorn (if installed) or standard library http.server fallback.
Endpoints:
  GET  /health   -> Service health status
  POST /analyze  -> Arabic & multilingual text sentiment & structure analysis
"""

import argparse
import json
import sys

def analyze_text(text: str) -> dict:
    words = text.split()
    positive_keywords = ["ممتاز", "رائع", "جميل", "ناجح", "مذهل", "good", "great", "excellent", "fast"]
    negative_keywords = ["سيء", "بطيء", "فاشل", "خطأ", "bad", "slow", "terrible", "error"]

    sentiment = "neutral"
    if any(k in text for k in positive_keywords):
        sentiment = "positive"
    elif any(k in text for k in negative_keywords):
        sentiment = "negative"

    return {
        "status": "success",
        "service": "ai",
        "received_text": text,
        "char_count": len(text),
        "words_count": len(words),
        "sentiment": sentiment,
        "python_version": sys.version.split()[0],
    }

def run_fastapi(host: str, port: int):
    from fastapi import FastAPI
    import uvicorn

    app = FastAPI(title="LaraPython AI Microservice", version="1.0.0")

    @app.get("/health")
    def health():
        return {
            "status": "healthy",
            "service": "ai",
            "version": "1.0.0",
            "engine": "fastapi"
        }

    @app.post("/analyze")
    def analyze(payload: dict):
        text = payload.get("text", "")
        return analyze_text(text)

    uvicorn.run(app, host=host, port=port, log_level="warning")

def run_stdlib(host: str, port: int):
    from http.server import HTTPServer, BaseHTTPRequestHandler

    class MicroserviceHandler(BaseHTTPRequestHandler):
        def do_GET(self):
            if self.path == "/health":
                self.send_response(200)
                self.send_header("Content-Type", "application/json; charset=utf-8")
                self.end_headers()
                response = {
                    "status": "healthy",
                    "service": "ai",
                    "version": "1.0.0",
                    "engine": "stdlib_http"
                }
                self.wfile.write(json.dumps(response).encode("utf-8"))
            else:
                self.send_response(404)
                self.end_headers()

        def do_POST(self):
            if self.path == "/analyze":
                content_length = int(self.headers.get("Content-Length", 0))
                body = self.rfile.read(content_length).decode("utf-8")
                try:
                    payload = json.loads(body) if body else {}
                except Exception:
                    payload = {}

                text = payload.get("text", "")
                result = analyze_text(text)

                self.send_response(200)
                self.send_header("Content-Type", "application/json; charset=utf-8")
                self.end_headers()
                self.wfile.write(json.dumps(result, ensure_ascii=False).encode("utf-8"))
            else:
                self.send_response(404)
                self.end_headers()

        def log_message(self, format, *args):
            # Suppress default noisy access logs
            return

    server = HTTPServer((host, port), MicroserviceHandler)
    server.serve_forever()

if __name__ == "__main__":
    parser = argparse.ArgumentParser(description="LaraPython AI Microservice")
    parser.add_argument("--host", default="127.0.0.1", help="Host to bind")
    parser.add_argument("--port", type=int, default=8001, help="Port to listen on")
    args = parser.parse_args()

    try:
        run_fastapi(args.host, args.port)
    except ImportError:
        run_stdlib(args.host, args.port)

