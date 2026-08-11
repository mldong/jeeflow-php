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

CREATE TABLE IF NOT EXISTS wf_process_design (
  id            VARCHAR(64)  NOT NULL COMMENT '主键',
  name          VARCHAR(100) NOT NULL COMMENT '流程编码',
  display_name  VARCHAR(200) NOT NULL COMMENT '流程显示名称',
  type          VARCHAR(50)  NULL DEFAULT 'approval' COMMENT '流程类型',
  icon          VARCHAR(200) NULL COMMENT '图标',
  is_deployed   INT          NULL DEFAULT 0 COMMENT '是否已部署(0:否；1:是)',
  remark        TEXT         NULL COMMENT '备注',
  create_time   DATETIME(3)  NULL COMMENT '创建时间',
  create_user   VARCHAR(64)  NULL COMMENT '创建用户',
  update_time   DATETIME(3)  NULL COMMENT '更新时间',
  update_user   VARCHAR(64)  NULL COMMENT '更新用户',
  PRIMARY KEY (id),
  KEY idx_process_design_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS wf_process_design_his (
  id                VARCHAR(64)  NOT NULL COMMENT '主键',
  process_design_id VARCHAR(64)  NOT NULL COMMENT '流程设计ID',
  content           TEXT         NULL COMMENT '流程模型定义',
  create_time       DATETIME(3)  NULL COMMENT '创建时间',
  create_user       VARCHAR(64)  NULL COMMENT '创建用户',
  PRIMARY KEY (id),
  KEY idx_process_design_his_pdid (process_design_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS wf_process_surrogate (
  id            VARCHAR(64)  NOT NULL COMMENT '主键',
  process_name  VARCHAR(100) NULL COMMENT '流程编码(为空=全部流程)',
  operator      VARCHAR(64)  NOT NULL COMMENT '授权人',
  surrogate     VARCHAR(64)  NOT NULL COMMENT '代理人',
  start_time    DATETIME(3)  NULL COMMENT '授权开始时间',
  end_time      DATETIME(3)  NULL COMMENT '授权结束时间',
  enabled       INT          NULL DEFAULT 1 COMMENT '是否启用(1:启用；0:停用)',
  create_time   DATETIME(3)  NULL COMMENT '创建时间',
  create_user   VARCHAR(64)  NULL COMMENT '创建用户',
  update_time   DATETIME(3)  NULL COMMENT '更新时间',
  update_user   VARCHAR(64)  NULL COMMENT '更新用户',
  PRIMARY KEY (id),
  KEY idx_process_surrogate_op (operator),
  KEY idx_process_surrogate_sur (surrogate)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
