<?php
// Helper untuk rekap laporan penjualan (materialized summary)

function ensure_sales_summary_tables(PDO $db) {
    $db->exec("CREATE TABLE IF NOT EXISTS sales_summary_period (
        period_type TEXT,
        period_key TEXT,
        qty INTEGER,
        qty_retur INTEGER,
        qty_rusak INTEGER,
        qty_invalid INTEGER,
        gross INTEGER,
        rusak INTEGER,
        invalid INTEGER,
        net INTEGER,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (period_type, period_key)
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS sales_summary_block (
        period_type TEXT,
        period_key TEXT,
        blok_name TEXT,
        qty INTEGER,
        qty_retur INTEGER,
        qty_rusak INTEGER,
        qty_invalid INTEGER,
        gross INTEGER,
        rusak INTEGER,
        invalid INTEGER,
        net INTEGER,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (period_type, period_key, blok_name)
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS sales_summary_profile (
        period_type TEXT,
        period_key TEXT,
        profile_name TEXT,
        qty INTEGER,
        qty_retur INTEGER,
        qty_rusak INTEGER,
        qty_invalid INTEGER,
        gross INTEGER,
        rusak INTEGER,
        invalid INTEGER,
        net INTEGER,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (period_type, period_key, profile_name)
    )");

    try { $db->exec("CREATE INDEX IF NOT EXISTS idx_sum_period_key ON sales_summary_period(period_type, period_key)"); } catch(Exception $e) {}
    try { $db->exec("CREATE INDEX IF NOT EXISTS idx_sum_block_key ON sales_summary_block(period_type, period_key, blok_name)"); } catch(Exception $e) {}
    try { $db->exec("CREATE INDEX IF NOT EXISTS idx_sum_profile_key ON sales_summary_profile(period_type, period_key, profile_name)"); } catch(Exception $e) {}
}

function rebuild_sales_summary(PDO $db) {
    ensure_sales_summary_tables($db);

    $db->exec("DELETE FROM sales_summary_period");
    $db->exec("DELETE FROM sales_summary_block");
    $db->exec("DELETE FROM sales_summary_profile");

    $db->exec("DROP VIEW IF EXISTS sales_norm");
    $db->exec("CREATE TEMP VIEW sales_norm AS
        SELECT
            COALESCE(NULLIF(sale_date,''), '') AS sale_date,
            COALESCE(NULLIF(profile_snapshot,''), NULLIF(profile,''), '-') AS profile_name,
            COALESCE(NULLIF(blok_name,''), '-') AS blok_name,
            COALESCE(NULLIF(price_snapshot,''), price, 0) AS price,
            CASE
                WHEN lower(COALESCE(status,''))='invalid' OR lower(COALESCE(comment,'')) LIKE '%invalid%' THEN 'invalid'
                WHEN lower(COALESCE(status,''))='rusak' OR lower(COALESCE(comment,'')) LIKE '%rusak%' THEN 'rusak'
                WHEN lower(COALESCE(status,''))='retur' OR lower(COALESCE(comment,'')) LIKE '%retur%' THEN 'retur'
                ELSE 'normal'
            END AS status_norm
        FROM sales_history
        WHERE COALESCE(NULLIF(sale_date,''), '') != ''
    ");

    $periods = [
        'day' => 'sale_date',
        'month' => 'substr(sale_date,1,7)',
        'year' => 'substr(sale_date,1,4)'
    ];

    foreach ($periods as $ptype => $pkeyExpr) {
        $db->exec("INSERT INTO sales_summary_period
            (period_type, period_key, qty, qty_retur, qty_rusak, qty_invalid, gross, rusak, invalid, net)
            SELECT
                '$ptype' AS period_type,
                $pkeyExpr AS period_key,
                COUNT(1) AS qty,
                SUM(CASE WHEN status_norm='retur' THEN 1 ELSE 0 END) AS qty_retur,
                SUM(CASE WHEN status_norm='rusak' THEN 1 ELSE 0 END) AS qty_rusak,
                SUM(CASE WHEN status_norm='invalid' THEN 1 ELSE 0 END) AS qty_invalid,
                SUM(CASE WHEN status_norm IN ('retur','invalid') THEN 0 ELSE price END) AS gross,
                SUM(CASE WHEN status_norm='rusak' THEN price ELSE 0 END) AS rusak,
                SUM(CASE WHEN status_norm='invalid' THEN price ELSE 0 END) AS invalid,
                (SUM(CASE WHEN status_norm IN ('retur','invalid') THEN 0 ELSE price END)
                 - SUM(CASE WHEN status_norm='rusak' THEN price ELSE 0 END)
                 - SUM(CASE WHEN status_norm='invalid' THEN price ELSE 0 END)) AS net
            FROM sales_norm
            GROUP BY $pkeyExpr
        ");

        $db->exec("INSERT INTO sales_summary_block
            (period_type, period_key, blok_name, qty, qty_retur, qty_rusak, qty_invalid, gross, rusak, invalid, net)
            SELECT
                '$ptype' AS period_type,
                $pkeyExpr AS period_key,
                blok_name,
                COUNT(1) AS qty,
                SUM(CASE WHEN status_norm='retur' THEN 1 ELSE 0 END) AS qty_retur,
                SUM(CASE WHEN status_norm='rusak' THEN 1 ELSE 0 END) AS qty_rusak,
                SUM(CASE WHEN status_norm='invalid' THEN 1 ELSE 0 END) AS qty_invalid,
                SUM(CASE WHEN status_norm IN ('retur','invalid') THEN 0 ELSE price END) AS gross,
                SUM(CASE WHEN status_norm='rusak' THEN price ELSE 0 END) AS rusak,
                SUM(CASE WHEN status_norm='invalid' THEN price ELSE 0 END) AS invalid,
                (SUM(CASE WHEN status_norm IN ('retur','invalid') THEN 0 ELSE price END)
                 - SUM(CASE WHEN status_norm='rusak' THEN price ELSE 0 END)
                 - SUM(CASE WHEN status_norm='invalid' THEN price ELSE 0 END)) AS net
            FROM sales_norm
            GROUP BY $pkeyExpr, blok_name
        ");

        $db->exec("INSERT INTO sales_summary_profile
            (period_type, period_key, profile_name, qty, qty_retur, qty_rusak, qty_invalid, gross, rusak, invalid, net)
            SELECT
                '$ptype' AS period_type,
                $pkeyExpr AS period_key,
                profile_name,
                COUNT(1) AS qty,
                SUM(CASE WHEN status_norm='retur' THEN 1 ELSE 0 END) AS qty_retur,
                SUM(CASE WHEN status_norm='rusak' THEN 1 ELSE 0 END) AS qty_rusak,
                SUM(CASE WHEN status_norm='invalid' THEN 1 ELSE 0 END) AS qty_invalid,
                SUM(CASE WHEN status_norm IN ('retur','invalid') THEN 0 ELSE price END) AS gross,
                SUM(CASE WHEN status_norm='rusak' THEN price ELSE 0 END) AS rusak,
                SUM(CASE WHEN status_norm='invalid' THEN price ELSE 0 END) AS invalid,
                (SUM(CASE WHEN status_norm IN ('retur','invalid') THEN 0 ELSE price END)
                 - SUM(CASE WHEN status_norm='rusak' THEN price ELSE 0 END)
                 - SUM(CASE WHEN status_norm='invalid' THEN price ELSE 0 END)) AS net
            FROM sales_norm
            GROUP BY $pkeyExpr, profile_name
        ");
    }
}
