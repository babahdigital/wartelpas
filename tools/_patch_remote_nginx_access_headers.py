from pathlib import Path

path = Path('/home/abdullah/sobigidul/infrastructure/nginx/conf.d/app.prod.conf')
text = path.read_text()

head, sep, tail = text.partition('# Konfigurasi server utama yang mendengarkan di port 80')
if not sep:
    raise SystemExit('marker server block utama tidak ditemukan')

old_seq = (
    '        proxy_set_header X-Forwarded-Proto $scheme;\n'
    '        proxy_set_header CF-Connecting-IP $http_cf_connecting_ip;\n'
    '        proxy_set_header True-Client-IP $http_cf_connecting_ip;\n'
)
new_seq = (
    '        proxy_set_header X-Forwarded-Proto $scheme;\n'
    '        proxy_set_header CF-Connecting-IP $http_cf_connecting_ip;\n'
    '        proxy_set_header True-Client-IP $http_cf_connecting_ip;\n'
    '        proxy_set_header CF-Access-Authenticated-User-Email $http_cf_access_authenticated_user_email;\n'
    '        proxy_set_header Cf-Access-Jwt-Assertion $http_cf_access_jwt_assertion;\n'
)

if old_seq in head and 'proxy_set_header CF-Access-Authenticated-User-Email $http_cf_access_authenticated_user_email;' not in head:
    head = head.replace(old_seq, new_seq)

updated = head + sep + tail
if updated == text:
    print('NO_CHANGE')
else:
    path.write_text(updated)
    print('PATCHED')
