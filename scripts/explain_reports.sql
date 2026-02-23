-- 启灵医美养生门店：报表 SQL 执行计划检查脚本
-- 使用方法：
-- 1) 登录 MySQL/MariaDB，USE 业务库
-- 2) 修改下方时间参数
-- 3) 执行本文件，查看 EXPLAIN 结果中的 key / rows / Extra

SET @from_at = '2026-01-01 00:00:00';
SET @to_at   = '2026-01-31 23:59:59';

-- 如需测试单店，请把下面 SQL 的 store_id 替换为真实门店ID
-- 如需测试总部视角，执行“不带 store_id 条件”的版本

-- 1) 运营总览：支付汇总（总部）
EXPLAIN
SELECT
  SUM(CASE WHEN p.status = 'paid' AND p.amount > 0 THEN p.amount ELSE 0 END) AS paid_amount,
  SUM(CASE WHEN p.status = 'refunded' OR p.amount < 0 THEN ABS(p.amount) ELSE 0 END) AS refund_amount,
  COUNT(CASE WHEN p.status = 'paid' AND p.amount > 0 THEN 1 ELSE NULL END) AS paid_txn_count,
  COUNT(CASE WHEN p.status = 'refunded' OR p.amount < 0 THEN 1 ELSE NULL END) AS refund_txn_count,
  COUNT(DISTINCT CASE WHEN p.status = 'paid' AND p.amount > 0 THEN p.order_id ELSE NULL END) AS paid_orders,
  COUNT(DISTINCT CASE WHEN p.status = 'refunded' OR p.amount < 0 THEN p.order_id ELSE NULL END) AS refund_orders
FROM qiling_order_payments p
INNER JOIN qiling_orders o ON o.id = p.order_id
WHERE p.paid_at >= @from_at
  AND p.paid_at <= @to_at
  AND p.status IN ('paid', 'refunded');

-- 2) 运营总览：支付汇总（单店）
EXPLAIN
SELECT
  SUM(CASE WHEN p.status = 'paid' AND p.amount > 0 THEN p.amount ELSE 0 END) AS paid_amount,
  SUM(CASE WHEN p.status = 'refunded' OR p.amount < 0 THEN ABS(p.amount) ELSE 0 END) AS refund_amount,
  COUNT(DISTINCT CASE WHEN p.status = 'paid' AND p.amount > 0 THEN p.order_id ELSE NULL END) AS paid_orders
FROM qiling_order_payments p
INNER JOIN qiling_orders o ON o.id = p.order_id
WHERE p.paid_at >= @from_at
  AND p.paid_at <= @to_at
  AND p.status IN ('paid', 'refunded')
  AND o.store_id = 1;

-- 3) 营收趋势（日）
EXPLAIN
SELECT
  DATE(p.paid_at) AS report_date,
  SUM(CASE WHEN p.status = 'paid' AND p.amount > 0 THEN p.amount ELSE 0 END) AS paid_amount,
  SUM(CASE WHEN p.status = 'refunded' OR p.amount < 0 THEN ABS(p.amount) ELSE 0 END) AS refund_amount,
  COUNT(DISTINCT CASE WHEN p.status = 'paid' AND p.amount > 0 THEN p.order_id ELSE NULL END) AS paid_orders
FROM qiling_order_payments p
INNER JOIN qiling_orders o ON o.id = p.order_id
WHERE p.paid_at >= @from_at
  AND p.paid_at <= @to_at
  AND p.status IN ('paid', 'refunded')
GROUP BY DATE(p.paid_at)
ORDER BY report_date ASC;

-- 4) 渠道成交分析（单店）
EXPLAIN
SELECT
  COALESCE(NULLIF(TRIM(c.source_channel), ''), '未标记') AS source_channel,
  COUNT(DISTINCT CASE WHEN p.status = 'paid' AND p.amount > 0 THEN p.order_id ELSE NULL END) AS paid_orders,
  SUM(CASE WHEN p.status = 'paid' AND p.amount > 0 THEN p.amount ELSE 0 END) AS paid_amount,
  SUM(CASE WHEN p.status = 'refunded' OR p.amount < 0 THEN ABS(p.amount) ELSE 0 END) AS refund_amount
FROM qiling_order_payments p
INNER JOIN qiling_orders o ON o.id = p.order_id
INNER JOIN qiling_customers c ON c.id = o.customer_id
WHERE p.paid_at >= @from_at
  AND p.paid_at <= @to_at
  AND p.status IN ('paid', 'refunded')
  AND o.store_id = 1
GROUP BY COALESCE(NULLIF(TRIM(c.source_channel), ''), '未标记');

-- 5) 项目排行（单店）
EXPLAIN
SELECT
  oi.item_type,
  COALESCE(oi.item_ref_id, 0) AS item_ref_id,
  oi.item_name,
  SUM(oi.qty) AS total_qty,
  COUNT(DISTINCT oi.order_id) AS order_count,
  SUM(oi.final_amount) AS sales_amount
FROM qiling_order_items oi
INNER JOIN (
  SELECT DISTINCT p.order_id
  FROM qiling_order_payments p
  INNER JOIN qiling_orders oo ON oo.id = p.order_id
  WHERE p.status = 'paid'
    AND p.amount > 0
    AND p.paid_at >= @from_at
    AND p.paid_at <= @to_at
    AND oo.store_id = 1
) paid_orders ON paid_orders.order_id = oi.order_id
GROUP BY oi.item_type, COALESCE(oi.item_ref_id, 0), oi.item_name
ORDER BY sales_amount DESC, total_qty DESC
LIMIT 20;

-- 6) 支付方式分析（单店）
EXPLAIN
SELECT
  p.pay_method,
  COUNT(*) AS txn_count,
  COUNT(DISTINCT p.order_id) AS order_count,
  SUM(CASE WHEN p.status = 'paid' AND p.amount > 0 THEN p.amount ELSE 0 END) AS paid_amount,
  SUM(CASE WHEN p.status = 'refunded' OR p.amount < 0 THEN ABS(p.amount) ELSE 0 END) AS refund_amount
FROM qiling_order_payments p
INNER JOIN qiling_orders o ON o.id = p.order_id
WHERE p.paid_at >= @from_at
  AND p.paid_at <= @to_at
  AND p.status IN ('paid', 'refunded')
  AND o.store_id = 1
GROUP BY p.pay_method;

-- 7) 门店日报（总部）
EXPLAIN
SELECT
  o.store_id,
  DATE(o.paid_at) AS report_date,
  COUNT(o.id) AS paid_orders,
  SUM(o.payable_amount) AS paid_amount,
  COUNT(DISTINCT o.customer_id) AS paid_customers
FROM qiling_orders o
WHERE o.status = 'paid'
  AND o.paid_at >= @from_at
  AND o.paid_at <= @to_at
GROUP BY o.store_id, DATE(o.paid_at)
ORDER BY report_date DESC, o.store_id ASC;

-- 8) 客户复购（单店）
EXPLAIN
SELECT
  c.id AS customer_id,
  COUNT(o.id) AS paid_orders,
  SUM(o.payable_amount) AS total_spent
FROM qiling_orders o
INNER JOIN qiling_customers c ON c.id = o.customer_id
WHERE o.status = 'paid'
  AND o.paid_at >= @from_at
  AND o.paid_at <= @to_at
  AND o.store_id = 1
GROUP BY c.id
HAVING COUNT(o.id) >= 2
ORDER BY paid_orders DESC, total_spent DESC;
