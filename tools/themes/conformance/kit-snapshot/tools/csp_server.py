#!/usr/bin/env python3
"""Serve the repo with the Juwri storefront CSP (core StorefrontPolicy as of 2026-10-02), tightened the way the
theme contract §7 asks kit themes to be ready for: no style attributes either. Used by tools/a11y.mjs (--csp).

  python3 tools/csp_server.py [port]
"""
import functools, http.server, os, sys

CSP = ("base-uri 'self'; default-src 'self'; connect-src 'self'; form-action 'self'; frame-ancestors 'self'; "
       "object-src 'none'; img-src 'self' data: https:; font-src 'self' data: https:; media-src 'self' https:; "
       "script-src 'self'; script-src-attr 'none'; style-src 'self' https:; style-src-attr 'none'")


class Handler(http.server.SimpleHTTPRequestHandler):
    def end_headers(self):
        self.send_header('Content-Security-Policy', CSP)
        super().end_headers()

    def log_message(self, *a):
        pass


if __name__ == '__main__':
    port = int(sys.argv[1]) if len(sys.argv) > 1 else 8790
    root = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
    http.server.ThreadingHTTPServer(('127.0.0.1', port), functools.partial(Handler, directory=root)).serve_forever()
