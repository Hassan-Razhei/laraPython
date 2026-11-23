import sys
import json
import base64

def get_payload():
    """Extract structured JSON payload passed from Laravel."""
    for arg in sys.argv[1:]:
        if arg.startswith('--payload-base64='):
            raw_b64 = arg.split('=', 1)[1]
            try:
                raw_json = base64.b64decode(raw_b64).decode('utf-8')
                return json.loads(raw_json)
            except Exception as e:
                sys.stderr.write(f"Failed to decode base64 payload: {e}\n")
                return {}
        if arg.startswith('--payload='):
            raw_json = arg.split('=', 1)[1]
            try:
                return json.loads(raw_json)
            except Exception as e:
                sys.stderr.write(f"Failed to decode json payload: {e}\n")
                return {}
    return {}

def send_response(data):
    """Output structured JSON response for Laravel with UTF-8 encoding."""
    print(json.dumps(data, ensure_ascii=False))

payload = get_payload
respond = send_response

