CREATE TABLE IF NOT EXISTS eol_product_status (
  rec_unid varchar(50) NOT NULL,
  plu_cd varchar(20) NOT NULL,
  status varchar(64) DEFAULT NULL,
  status_nm varchar(10) DEFAULT NULL,
  plu_name varchar(200) DEFAULT NULL,
  reason text DEFAULT NULL,
  prod_discon_date datetime DEFAULT NULL,
  stock_money decimal(18, 4) DEFAULT NULL,
  total_stock decimal(18, 4) DEFAULT NULL,
  sale_plan decimal(18, 4) DEFAULT NULL,
  product_plan decimal(18, 4) DEFAULT NULL,
  disp_plan decimal(18, 4) DEFAULT NULL,
  stock_001 decimal(18, 4) DEFAULT NULL,
  signal_001 varchar(10) DEFAULT NULL,
  stock_002 decimal(18, 4) DEFAULT NULL,
  signal_002 varchar(10) DEFAULT NULL,
  ins_datetime datetime DEFAULT NULL,
  ins_user varchar(50) DEFAULT NULL,
  request_no varchar(100) DEFAULT NULL,
  notes_link varchar(100) DEFAULT NULL,
  PRIMARY KEY (rec_unid, plu_cd),
  KEY idx_eol_product_status_ins_datetime (ins_datetime),
  KEY idx_eol_product_status_plu_name (plu_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS eol_summary (
  id int NOT NULL AUTO_INCREMENT,
  product_no varchar(255) DEFAULT NULL,
  product_name varchar(255) DEFAULT NULL,
  registered_at datetime DEFAULT NULL,
  reason varchar(255) DEFAULT NULL,
  PRIMARY KEY (id),
  KEY idx_eol_summary_product_no (product_no)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS eol_detail (
  id int NOT NULL AUTO_INCREMENT,
  product_no varchar(255) DEFAULT NULL,
  kubun varchar(255) DEFAULT NULL,
  assignee varchar(255) DEFAULT NULL,
  due_date date DEFAULT NULL,
  comment text DEFAULT NULL,
  detail text DEFAULT NULL,
  status varchar(255) DEFAULT NULL,
  created_at datetime DEFAULT CURRENT_TIMESTAMP,
  updated_at datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_eol_detail_product_no (product_no),
  KEY idx_eol_detail_latest (product_no, kubun, updated_at, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
