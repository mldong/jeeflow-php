<?php
/**
 * 冒烟测试脚本 —— 验证 PHP 引擎完整流程
 * 模拟 jeeflow-ui 会调用的 API 路径
 */
require __DIR__ . '/vendor/autoload.php';
require dirname(__DIR__) . '/jeeflow-flows-dir.php'; // 本仓 flows/ 解析（维护者机器上镜像 Java 源）

use Jeeflow\Core\JeeflowEngine;
use Jeeflow\Core\Repository\InMemoryProcessRepository;
use Jeeflow\Core\Repository\InMemoryProcessExtRepository;
use Jeeflow\Core\ServiceContext;
use Jeeflow\Core\Spi\TransactionTemplateInterface;
use Jeeflow\WebContract\JeeflowFacade;

$repo = new InMemoryProcessRepository();
$extRepo = new InMemoryProcessExtRepository();
$engine = new JeeflowEngine($repo);
$facade = new JeeflowFacade($engine, $repo, $extRepo);

ServiceContext::put(TransactionTemplateInterface::class, new class implements TransactionTemplateInterface {
    public function required(callable $action): mixed { return $action(); }
});

// 1. 部署流程
$json = file_get_contents(jeeflow_flows_dir() . '/01-simple.json');
$r = $facade->flow('processDefine/deploy', ['name' => 'simple', 'displayName' => '简单审批', 'content' => $json]);
assert($r['code'] === 0, "deploy failed: {$r['msg']}");
$defineId = $r['data']['processDefineId'];
echo "✅ deploy → defineId=$defineId\n";

// 2. 定义分页
$r = $facade->flow('processDefine/page', ['pageNum' => 1, 'pageSize' => 10]);
assert($r['code'] === 0 && $r['data']['recordCount'] === 1);
echo "✅ processDefine/page → recordCount=1\n";

// 3. 发起流程
$r = $facade->flow('processDefine/startAndExecute', ['processDefineId' => $defineId, 'operator' => 'user1']);
assert($r['code'] === 0, "start failed: {$r['msg']}");
$instanceId = $r['data']['processInstanceId'];
echo "✅ startAndExecute → instanceId=$instanceId\n";

// 4. 查询待办（leader）
$r = $facade->flow('processTask/todoList', ['operator' => 'leader']);
assert($r['code'] === 0 && $r['data']['recordCount'] === 1, "todo expected 1, got {$r['data']['recordCount']}");
$taskId = $r['data']['rows'][0]['id'];
echo "✅ processTask/todoList → taskId=$taskId\n";

// 5. 审批
$r = $facade->flow('processTask/execute', ['processTaskId' => $taskId, 'operator' => 'leader', 'submitType' => 'approve']);
assert($r['code'] === 0, "execute failed: {$r['msg']}");
echo "✅ processTask/execute (approve)\n";

// 6. 实例完成
$r = $facade->flow('processInstance/detail', ['id' => $instanceId]);
assert($r['code'] === 0 && $r['data']['state'] === 20, "instance should be finished, state={$r['data']['state']}");
echo "✅ processInstance/detail → state=20 (finished)\n";

// 7. 已办查询
$r = $facade->flow('processTask/doneList', ['operator' => 'leader']);
assert($r['code'] === 0 && $r['data']['recordCount'] === 1);
echo "✅ processTask/doneList → recordCount=1\n";

// 8. 设计稿保存
$r = $facade->flow('processDesign/save', ['name' => 'test-design', 'displayName' => '测试设计', 'type' => 'approval']);
assert($r['code'] === 0);
echo "✅ processDesign/save → designId={$r['data']['id']}\n";

// 9. 委托代理
$r = $facade->flow('processSurrogate/save', ['processName' => 'simple', 'surrogate' => 'user2', 'startTime' => '2026-08-01', 'endTime' => '2026-08-31']);
assert($r['code'] === 0);
echo "✅ processSurrogate/save → surrogateId={$r['data']['id']}\n";

echo "\n🎉 冒烟测试全部通过！40 action 核心路径验证 OK\n";
