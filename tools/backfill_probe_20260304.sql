.headers off
.mode list
.separator |
WITH lh AS (
  SELECT
    username,
    COALESCE(NULLIF(substr(last_login_real,1,10),''), NULLIF(substr(first_login_real,1,10),''), NULLIF(substr(login_time_real,1,10),''), login_date) AS sale_date,
    lower(COALESCE(NULLIF(last_status,''),'normal')) AS st,
    COALESCE(NULLIF(raw_comment,''),'') AS raw_comment,
    COALESCE(NULLIF(blok_name,''),'') AS blok_name,
    COALESCE(NULLIF(validity,''),'') AS validity,
    CAST(COALESCE(NULLIF(price,''),0) AS INTEGER) AS price
  FROM login_history
  WHERE username != ''
),
cand AS (
  SELECT
    username,
    sale_date,
    CASE WHEN st IN ('rusak','retur','invalid') THEN st ELSE 'normal' END AS status,
    CASE
      WHEN price > 0 THEN price
      WHEN lower(validity) LIKE '%30%' OR lower(raw_comment) LIKE '%30%' THEN 20000
      WHEN lower(validity) LIKE '%10%' OR lower(raw_comment) LIKE '%10%' THEN 5000
      ELSE 5000
    END AS inferred_price
  FROM lh
  WHERE sale_date > '2026-02-15'
    AND sale_date < date('now','localtime')
    AND (blok_name != '' OR lower(raw_comment) LIKE '%blok%')
    AND NOT EXISTS (
      SELECT 1
      FROM sales_history sh
      WHERE sh.username = lh.username
        AND sh.sale_date = lh.sale_date
    )
)
SELECT
  count(*),
  COALESCE(min(sale_date), '-'),
  COALESCE(max(sale_date), '-'),
  COALESCE(sum(inferred_price), 0),
  COALESCE(sum(CASE WHEN status = 'rusak' THEN 1 ELSE 0 END), 0),
  COALESCE(sum(CASE WHEN status = 'retur' THEN 1 ELSE 0 END), 0),
  COALESCE(sum(CASE WHEN status = 'invalid' THEN 1 ELSE 0 END), 0),
  COALESCE(sum(CASE WHEN status = 'normal' THEN 1 ELSE 0 END), 0)
FROM cand;
