from pathlib import Path

path = Path('/home/abdullah/sobigidul/infrastructure/nginx/conf.d/app.prod.conf')
text = path.read_text()

head, sep, tail = text.partition('# Konfigurasi server utama yang mendengarkan di port 80')
if not sep:
    raise SystemExit('marker server block utama tidak ditemukan')

server_marker = '    server_name wartelpas.sobigidul.com;\n\n'
realip_block = (
    '    # Real client IP dari Cloudflare Tunnel\n'
    '    set_real_ip_from 127.0.0.1;\n'
    '    set_real_ip_from 172.16.0.0/12;\n'
    '    set_real_ip_from 10.0.0.0/8;\n'
    '    real_ip_header CF-Connecting-IP;\n'
    '    real_ip_recursive on;\n\n'
)
if server_marker in head and 'real_ip_header CF-Connecting-IP;' not in head:
    head = head.replace(server_marker, server_marker + realip_block, 1)

asset_old = (
    '        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;\n'
    '        proxy_set_header X-Forwarded-Proto $scheme;\n'
)
asset_new = (
    '        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;\n'
    '        proxy_set_header X-Forwarded-Proto $scheme;\n'
    '        proxy_set_header CF-Connecting-IP $http_cf_connecting_ip;\n'
    '        proxy_set_header True-Client-IP $http_cf_connecting_ip;\n'
)
if asset_old in head and 'proxy_set_header CF-Connecting-IP $http_cf_connecting_ip;' not in head.split('location / {')[0]:
    head = head.replace(asset_old, asset_new, 1)

main_old = (
    '        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;\n'
    '        proxy_set_header X-Forwarded-Proto $scheme;\n\n'
)
main_new = (
    '        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;\n'
    '        proxy_set_header X-Forwarded-Proto $scheme;\n'
    '        proxy_set_header CF-Connecting-IP $http_cf_connecting_ip;\n'
    '        proxy_set_header True-Client-IP $http_cf_connecting_ip;\n\n'
)
if main_old in head and head.count('proxy_set_header CF-Connecting-IP $http_cf_connecting_ip;') < 2:
    head = head.replace(main_old, main_new, 1)

updated = head + sep + tail
if updated == text:
    print('NO_CHANGE')
else:
    path.write_text(updated)
    print('PATCHED')
