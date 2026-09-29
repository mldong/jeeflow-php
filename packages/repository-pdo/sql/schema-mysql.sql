-- MySQL 建表 SQL（jeeflow PHP 测试/演示用副本）
--
-- ⚠️ 本副本与唯一编辑源
--    jeeflow-java/jeeflow-repository-jdbc/src/test/resources/schema-mysql.sql **有意不逐字对齐**，
--    也不是 jeeflow-hub/scripts/sync-schema.sh 的分发目标。首行旧文案"对齐 Java schema-mysql.sql"
--    不实，2026-09-29 按 issues/70 第 10 项实测结论改为如实标注（owner 裁定：先量，别硬拉平）。
--
-- 差异清单（编辑源 → 本副本，2026-09-29 现读）：
--   ① id 类列（主键 + 外键）共 15 处：编辑源 BIGINT（schema-mysql.sql:4/20/21/22/39/40/50/63/64/74/75/88/104/105/114），
--      本副本 VARCHAR(64)：define.id / instance.id+parent_id+process_define_id /
--      task.id+process_instance_id+task_parent_id / task_actor.id+process_task_id /
--      cc_instance.id+process_instance_id / design.id / design_his.id+process_design_id / surrogate.id。
--      （actor_id、operator、create_user 这些两边都是 VARCHAR(64)，不算差异。）← 唯一有承重的一条
--   ② content：编辑源 BLOB（:9/:106），本副本 TEXT。
--   ③ 本副本 5 张核心表缺 KEY 索引（编辑源 :16/34/35/57/58/59/69/70/83/84）与列/表 COMMENT
--     （编辑源 :17/36/60/71/85/101/111/128 带 COMMENT='流程…'）。
--
-- 为什么 ① 不能直接拉平（拉平 = 真连 MySQL 的 PDO 套件红）：
--   tests/RepositoryPDO/PdoProcessRepositoryTest.php 与 PdoRepositoryEdgeCaseTest.php 直接 exec()
--   本文件建表，并往 id 列塞**非数字串**种子：
--     wf_process_define.id            'def-1'(前:77) 'd-null'(后:59) 'd-large'(后:73) 'd-uni'(后:86)
--                                     'd1'(后:105/128/157/172/311/340) "bulk-d-{$i}"(后:294)
--     wf_process_instance.id          'inst-1'(前:106) 'inst-2'(前:137) 'i-empty-vars'(后:109)
--                                     'i-complex'(后:132) "i-state-{$i}"(后:160) 'i-child'(后:178)
--                                     "bulk-i-{$i}"(后:314) 'i-with-tasks'(后:343)
--     wf_process_instance.parent_id   'parent-123'(后:175)
--     wf_process_task.id              'task-1'(前:142) 't-multi-actor'(后:196) 't-no-actor'(后:206)
--                                     "t-state-{$i}"(后:225) 't-vars'(后:237) 't-update'(后:249)
--                                     "bulk-t-{$i}"(后:325) "t-linked-{$i}"(后:349)
--     wf_process_task.process_instance_id / wf_process_cc_instance.process_instance_id
--                                     'i1'(后:195/205/224/236/248/269/276/283/324) 'inst-2'(前:141)
--                                     'i-with-tasks'(后:348) 'inst-1'(前:167)
--   160 的 MySQL 5.7.31 实测 @@sql_mode 含 STRICT_TRANS_TABLES ⇒ 非数字串进 BIGINT 列是
--   error 1366 "Incorrect integer value"，不是截断警告。第三套 PdoFlowAdvancedTest 只用数字 id
--   （defineSeq 自 1001 起）⇒ 不受影响。
--   ⇒ 拉平的前置条件是先把这约 40 处种子 id 归正成数字串（属测试数据改造，跨两个套件），
--     本文件单独改只会把坑挪到"新库/CI 复跑"时爆。
--   ⚠️ 还有个双向陷阱：三套 PDO 用的是 CREATE TABLE **IF NOT EXISTS**，现网 160/jeeflow_test 的
--     8 张表已是 varchar(64)，只改本文件**不会**改造已有库 ⇒ 拉平在旧库上静默不生效、只在全新库红。
--
-- 引擎侧对 id 的用法（实测：BIGINT 与 VARCHAR(64) 对运行时等价，只对"非数字种子"不等价）：
--   写侧统一 (string) 绑定（packages/repository-pdo/src/PdoProcessRepository.php:54/78/99/241/275/352…），
--   读侧 int→string 归正在 packages/repository-pdo/src/PdoValue.php（issues/68），
--   门面 JSON 出口 packages/web-contract/src/JeeflowFacade.php::stringifyIds（issues/75，
--   19 位雪花 id 防 JS float64 丢精度），委托台账取最新一条另有 SurrogateRule::pickLatest 数值序兜底
--   （PdoProcessExtRepository.php:333 注释明确按"id 列为 TEXT 时 ORDER BY id DESC 是字典序"设防）。
--
-- T1 冒烟不受本文件影响：tests/MysqlSmoke/MysqlSmokeTest.php 用**自己内联**的 DDL（:487-594，
--   主键已是 BIGINT）+ 独立库 jeeflow_php_t1（每次 DROP/CREATE），根本不读本文件。
--
-- 真库现状（2026-09-29 只读 information_schema.columns 实测）：160 上 wf_ 表分布在 20+ 个库
--   （jeeflow / jeeflow_moon / mldong-plus / mldong-plus-pro / mldong-plus-py / mldong-wat /
--   mldong-wat-gf / mldong-bill / mldong-jeeflow-app / hzltdb / mflow / testss …），PRI **全是
--   bigint(20)**；唯 jeeflow_test 是 varchar(64)，而它就是本文件建出来的那一份。
--   ⇒ 编辑源的 BIGINT 才是生态基准；本副本的 VARCHAR(64) 记的是 PHP 测试数据的债，不是方言差异。
--
-- ID 无自增：主键由应用层 IdGenerator 生成（跨库一致，spec §7）。
--   本目录另有 schema-sqlite.sql（TEXT 主键，SQLite 方言，不进分发列表）。
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
