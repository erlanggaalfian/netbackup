# File: python/telegram_util.py
# Helper kirim notifikasi Telegram (parse_mode HTML — aman untuk nama/IP perangkat).

import json
import urllib.error
import urllib.parse
import urllib.request


def escape_html(text):
    s = str(text)
    return s.replace('&', '&amp;').replace('<', '&lt;').replace('>', '&gt;')


def get_telegram_credentials(cursor):
    cursor.execute(
        "SELECT setting_key, setting_value FROM sys_settings "
        "WHERE setting_key IN ('telegram_bot_token', 'telegram_chat_id')"
    )
    rows = cursor.fetchall()
    token = next((r['setting_value'] for r in rows if r['setting_key'] == 'telegram_bot_token'), '')
    chat = next((r['setting_value'] for r in rows if r['setting_key'] == 'telegram_chat_id'), '')
    token = (token or '').strip()
    chat = (chat or '').strip()
    return token or None, chat or None


def send_telegram_html(token, chat_id, html_message, timeout=10):
    if not token or not chat_id:
        return False, 'Token atau Chat ID kosong'

    url = f"https://api.telegram.org/bot{token}/sendMessage"
    payload = urllib.parse.urlencode({
        'chat_id': chat_id,
        'text': html_message,
        'parse_mode': 'HTML',
        'disable_web_page_preview': 'true',
    }).encode('utf-8')

    try:
        with urllib.request.urlopen(urllib.request.Request(url, data=payload), timeout=timeout) as resp:
            body = json.loads(resp.read().decode('utf-8'))
            if body.get('ok'):
                return True, None
            return False, body.get('description', 'Telegram API error')
    except urllib.error.HTTPError as exc:
        try:
            err = json.loads(exc.read().decode('utf-8'))
            return False, err.get('description', str(exc))
        except Exception:
            return False, str(exc)
    except Exception as exc:
        return False, str(exc)


def send_telegram_from_cursor(cursor, html_message, timeout=10):
    token, chat_id = get_telegram_credentials(cursor)
    if not token or not chat_id:
        return False, 'Token atau Chat ID kosong di Pengaturan'
    return send_telegram_html(token, chat_id, html_message, timeout=timeout)
