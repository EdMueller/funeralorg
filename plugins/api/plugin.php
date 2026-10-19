<?php
declare(strict_types=1);

return function (PluginApi $p): void {
    $p->apiRoute('api', 'controllers/api.php', 'ctrl_api');
    $p->apiRoute('mcp', 'controllers/mcp.php', 'ctrl_mcp');
    $p->route('apitokens', 'controllers/tokens.php', 'ctrl_apitokens');
    $p->nav('apitokens', 'api.nav', 'bi-key', fn() => ApiTokens::mayManage(), 'account');
};
