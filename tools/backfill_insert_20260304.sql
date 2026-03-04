.bail on
.headers off
.mode list
BEGIN IMMEDIATE;
WITH lh AS (
  SELECT
    username,
    COALESCE(NULLIF(substr(last_login_real,1,10),''), NULLIF(substr(first_login_real,1,10),''), NULLIF(substr(login_time_real,1,10),''), login_date) AS sale_date,
    COALESCE(NULLIF(substr(last_login_real,12,8),''), NULLIF(substr(first_login_real,12,8),''), NULLIF(substr(login_time_real,12,8),''), login_time, '00:00:00') AS sale_time,
    lower(COALESCE(NULLIF(last_status,''),'normal')) AS st,
    COALESCE(NULLIF(raw_comment,''),'') AS raw_comment,
    COALESCE(NULLIF(blok_name,''),'') AS blok_name,
    COALESCE(NULLIF(validity,''),'') AS validity,
    CAST(COALESCE(NULLIF(price,''),0) AS INTEGER) AS price,
    COALESCE(NULLIF(customer_name,''), '') AS customer_name,
    COALESCE(NULLIF(room_name,''), '') AS room_name
  FROM login_history
  WHERE username != ''
),
cand AS (
  SELECT
    username,
    sale_date,
    sale_time,
    validity,
    raw_comment,
    blok_name,
    customer_name,
    room_name,
    CASE
      WHEN st IN ('rusak','retur','invalid') THEN st
      WHEN lower(raw_comment) LIKE '%retur%' THEN 'retur'
      WHEN lower(raw_comment) LIKE '%rusak%' THEN 'rusak'
      WHEN lower(raw_comment) LIKE '%invalid%' THEN 'invalid'
      ELSE 'normal'
    END AS status,
    CASE
      WHEN price > 0 THEN price
      WHEN lower(validity) LIKE '%30%' OR lower(raw_comment) LIKE '%30%' THEN 20000
      WHEN lower(validity) LIKE '%10%' OR lower(raw_comment) LIKE '%10%' THEN 5000
      ELSE 5000
    END AS inferred_price
  FROM lh
  WHERE sale_date > '2026-02-15'
    AND sale_date < date('now','localtime')
    AND sale_date != ''
    AND (blok_name != '' OR lower(raw_comment) LIKE '%blok%')
    AND NOT EXISTS (
      SELECT 1
      FROM sales_history sh
      WHERE sh.username = lh.username
        AND sh.sale_date = lh.sale_date
    )
)
INSERT OR IGNORE INTO sales_history (
  raw_date, raw_time, sale_date, sale_time, sale_datetime,
  username, profile, profile_snapshot,
  price, price_snapshot, sprice_snapshot, validity,
  comment, blok_name, status, is_rusak, is_retur, is_invalid, qty,
  full_raw_data, customer_name, room_name
)
SELECT
  sale_date,
  sale_time,
  sale_date,
  sale_time,
  CASE WHEN sale_time != '' THEN sale_date || ' ' || sale_time ELSE sale_date || ' 00:00:00' END,
  username,
  '',
  '',
  inferred_price,
  inferred_price,
  0,
  validity,
  raw_comment,
  blok_name,
  status,
  CASE WHEN status = 'rusak' THEN 1 ELSE 0 END,
  CASE WHEN status = 'retur' THEN 1 ELSE 0 END,
  CASE WHEN status = 'invalid' THEN 1 ELSE 0 END,
  1,
  raw_comment,
  customer_name,
  room_name
FROM cand;
SELECT 'inserted|' || changes();
COMMIT;
