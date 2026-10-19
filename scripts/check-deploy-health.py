"""Deployment requires both HTTP liveness and a healthy payment clock."""
import json
import sys


def healthy(data):
    return (data.get('status') == 'UP'
            and data.get('clock', {}).get('status') == 'RUNNING'
            and data.get('clock', {}).get('healthy') is True)


if __name__ == '__main__':
    with open(sys.argv[1]) as stream:
        sys.exit(0 if healthy(json.load(stream)) else 1)
