-- jeeflow 核心五表建表 SQL（SQLite 兼容版，用于测试）
-- ID 无自增：主键由应用层 IdGenerator 生成

CREATE TABLE IF NOT EXISTS wf_process_define (
  id          TEXT     NOT NULL,
  name        TEXT     NOT NULL,
  display_name TEXT    NOT NULL,
  type        TEXT     NULL,
  state       INTEGER  NULL,
  content     TEXT     NULL,
  version     INTEGER  NULL,
  create_time TEXT     NULL,
  create_user TEXT     NULL,
  update_time TEXT     NULL,
  update_user TEXT     NULL,
  PRIMARY KEY (id)
);

CREATE TABLE IF NOT EXISTS wf_process_instance (
  id               TEXT    NOT NULL,
  parent_id        TEXT    NULL,
  process_define_id TEXT   NULL,
  state            INTEGER NULL,
  parent_node_name TEXT    NULL,
  business_no      TEXT    NULL,
  operator         TEXT    NULL,
  expire_time      TEXT    NULL,
  variable         TEXT    NULL,
  create_time      TEXT    NULL,
  create_user      TEXT    NULL,
  update_time      TEXT    NULL,
  update_user      TEXT    NULL,
  PRIMARY KEY (id)
);

CREATE TABLE IF NOT EXISTS wf_process_task (
  id                  TEXT    NOT NULL,
  process_instance_id TEXT    NOT NULL,
  task_name           TEXT    NOT NULL,
  display_name        TEXT    NOT NULL,
  task_type           INTEGER NULL,
  perform_type        INTEGER NULL,
  task_state          INTEGER NULL,
  operator            TEXT    NULL,
  finish_time         TEXT    NULL,
  expire_time         TEXT    NULL,
  form_key            TEXT    NULL,
  task_parent_id      TEXT    NULL,
  variable            TEXT    NULL,
  create_time         TEXT    NULL,
  create_user         TEXT    NULL,
  update_time         TEXT    NULL,
  update_user         TEXT    NULL,
  PRIMARY KEY (id)
);

CREATE TABLE IF NOT EXISTS wf_process_task_actor (
  id              TEXT NOT NULL,
  process_task_id TEXT NOT NULL,
  actor_id        TEXT NOT NULL,
  create_time     TEXT NULL,
  create_user     TEXT NULL,
  PRIMARY KEY (id)
);

CREATE TABLE IF NOT EXISTS wf_process_cc_instance (
  id                  TEXT    NOT NULL,
  process_instance_id TEXT    NOT NULL,
  actor_id            TEXT    NOT NULL,
  state               INTEGER NULL DEFAULT 0,
  create_time         TEXT    NULL,
  create_user         TEXT    NULL,
  update_time         TEXT    NULL,
  update_user         TEXT    NULL,
  PRIMARY KEY (id)
);

CREATE TABLE IF NOT EXISTS wf_process_design (
  id            TEXT     NOT NULL,
  name          TEXT     NOT NULL,
  display_name  TEXT     NOT NULL,
  type          TEXT     NULL DEFAULT 'approval',
  icon          TEXT     NULL,
  is_deployed   INTEGER  NULL DEFAULT 0,
  remark        TEXT     NULL,
  create_time   TEXT     NULL,
  create_user   TEXT     NULL,
  update_time   TEXT     NULL,
  update_user   TEXT     NULL,
  PRIMARY KEY (id)
);

CREATE TABLE IF NOT EXISTS wf_process_design_his (
  id                TEXT    NOT NULL,
  process_design_id TEXT    NOT NULL,
  content           TEXT    NULL,
  create_time       TEXT    NULL,
  create_user       TEXT    NULL,
  PRIMARY KEY (id)
);

CREATE TABLE IF NOT EXISTS wf_process_surrogate (
  id            TEXT    NOT NULL,
  process_name  TEXT    NULL,
  operator      TEXT    NOT NULL,
  surrogate     TEXT    NOT NULL,
  start_time    TEXT    NULL,
  end_time      TEXT    NULL,
  enabled       INTEGER NULL DEFAULT 1,
  create_time   TEXT    NULL,
  create_user   TEXT    NULL,
  update_time   TEXT    NULL,
  update_user   TEXT    NULL,
  PRIMARY KEY (id)
);
