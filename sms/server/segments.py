"""GSM 03.38 accounting; the first lab release sends single GSM-7 segments only."""
import math

BASIC = set('@£$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !"#¤%&\'()*+,-./0123456789:;<=>?¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà')
EXTENDED = set('\f^{}\\[~]|€')
PREFIX = '[TEST UDZIOSMS] '


def count(text):
    gsm = all(c in BASIC or c in EXTENDED for c in text)
    units = sum(2 if c in EXTENDED else 1 for c in text) if gsm else len(text.encode('utf-16-be')) // 2
    limit, multipart = (160, 153) if gsm else (70, 67)
    return {'encoding': 'GSM-7' if gsm else 'Unicode', 'units': units,
            'segments': 1 if units <= limit else math.ceil(units / multipart),
            'supported': gsm and 0 < units <= 160}
