.bail on
ATTACH '/var/www/html/db_data/recovery_candidates/source_babahdigital_main_20260214_020000.db' AS src;
BEGIN IMMEDIATE;
INSERT INTO login_meta_queue (
  voucher_code,
  customer_name,
  room_name,
  blok_name,
  profile_name,
  price,
  session_id,
  client_ip,
  user_agent,
  created_at,
  consumed_at,
  consumed_by
)
SELECT
  s.voucher_code,
  s.customer_name,
  s.room_name,
  s.blok_name,
  s.profile_name,
  s.price,
  s.session_id,
  s.client_ip,
  s.user_agent,
  s.created_at,
  s.consumed_at,
  s.consumed_by
FROM src.login_meta_queue s
WHERE NOT EXISTS (
  SELECT 1
  FROM login_meta_queue t
  WHERE t.voucher_code = s.voucher_code
    AND t.created_at = s.created_at
);
SELECT 'inserted|' || changes();
COMMIT;
DETACH src;
