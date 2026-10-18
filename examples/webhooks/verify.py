import hashlib, hmac, json, re, sys

def verify_paykr_webhook(raw, signature, secret):
    if not secret or not re.fullmatch(r'sha256=[a-fA-F0-9]{64}', signature):
        raise ValueError('Invalid signature')
    expected = hmac.new(secret.encode(), raw, hashlib.sha256).hexdigest()
    if not hmac.compare_digest(expected, signature[7:].lower()):
        raise ValueError('Invalid signature')
    return json.loads(raw)

if __name__ == '__main__':
    value = json.load(sys.stdin)
    try:
        verify_paykr_webhook(value['body'].encode(), value['signature'], value['secret'])
        print('valid', end='')
    except (ValueError, TypeError):
        print('invalid', end='')
