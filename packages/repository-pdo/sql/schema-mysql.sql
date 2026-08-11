-- MySQL 建表 SQL（jeeflow PHP 测试用）—— 对齐 Java schema-mysql.sql
-- ID 无自增：主键由应用层 IdGenerator 生成
CREATE TABLE IF NOT EXISTS wf_process_define (
  id          VARCHAR(64)  NOT NULL COMMENT '主键',
  name        VARCHAR(64)  NOT NULL,
  display_name VARCHAR(100) NOT NULL,
  type        VARCHAR(32)  NULL,
  state       INT          NULL,
  content     TEXT         NULL,
  version     INT          NULL,
  create_time DATETIME(3)  NULL,
  create_user VARCHAR(64)  NULL,
  update_time DATETIME(3)  NULL,
  update_user VARCHAR(64)  NULL,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS wf_process_instance (
  id               VARCHAR(64) NOT NULL,
  parent_id        VARCHAR(64) NULL,
  process_define_id VARCHAR(64) NULL,
  state            INT         NULL,
  parent_node_name VARCHAR(100) NULL,
  business_no      VARCHAR(64) NULL,
  operator         VARCHAR(64) NULL,
  expire_time      DATETIME(3) NULL,
  variable         TEXT        NULL,
  create_time      DATETIME(3) NULL,
  create_user      VARCHAR(64) NULL,
  update_time      DATETIME(3) NULL,
  update_user      VARCHAR(64) NULL,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS wf_process_task (
  id                  VARCHAR(64) NOT NULL,
  process_instance_id VARCHAR(64) NOT NULL,
  task_name           VARCHAR(100) NOT NULL,
  display_name        VARCHAR(100) NOT NULL,
  task_type           INT         NULL,
  perform_type        INT         NULL,
  task_state          INT         NULL,
  operator            VARCHAR(64) NULL,
  finish_time         DATETIME(3) NULL,
  expire_time         DATETIME(3) NULL,
  form_key            VARCHAR(100) NULL,
  task_parent_id      VARCHAR(64) NULL,
  variable            TEXT        NULL,
  create_time         DATETIME(3) NULL,
  create_user         VARCHAR(64) NULL,
  update_time         DATETIME(3) NULL,
  update_user         VARCHAR(64) NULL,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS wf_process_task_actor (
  id              VARCHAR(64) NOT NULL,
  process_task_id VARCHAR(64) NOT NULL,
  actor_id        VARCHAR(64) NOT NULL,
  create_time     DATETIME(3) NULL,
  create_user     VARCHAR(64) NULL,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS wf_process_cc_instance (
  id                  VARCHAR(64) NOT NULL,
  process_instance_id VARCHAR(64) NOT NULL,
  actor_id            VARCHAR(64) NOT NULL,
  state               INT         NULL DEFAULT 0,
  create_time         DATETIME(3) NULL,
  create_user         VARCHAR(64) NULL,
  update_time         DATETIME(3) NULL,
  update_user         VARCHAR(64) NULL,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
