# ShopAGG App Store

用于在 WordPress 后台连接 ShopAGG 商店，浏览并安装插件/主题，同时接入购买与更新能力。

## 插件简介

ShopAGG App Store 为 WordPress 提供一个后台应用市场入口，管理员可通过 API Token 登录后：

- 浏览 ShopAGG 商店中的插件与主题
- 查看资源详情（版本、价格、描述、封面）
- 安装免费资源
- 购买并安装付费资源（当前为 MVP 支付流程）
- 将已安装资源纳入 ShopAGG 更新检测

## 主要功能

- API Token 认证登录
	- 在插件页面输入 Token，调用 `/me` 接口验证后建立会话
- 商店资源展示
	- 按 `Plugins` / `Themes` 分类展示资源列表
- 资源详情与安装
	- 详情页展示资源信息，支持安装、购买按钮逻辑
- 许可证校验
	- 付费资源通过 `licenses/verify` 按域名校验授权
- 自动更新接入
	- 对通过本插件安装并登记的资源，挂接到 WordPress 原生更新机制

## 环境要求

- WordPress >= 5.8
- PHP >= 7.4
- 具备管理员权限（`manage_options`）
- 服务器可访问 ShopAGG API 服务

## 安装方式

1. 将插件目录放入：
	 `wp-content/plugins/shopagg-app-store`
2. 在 WordPress 后台启用插件：
	 `插件 > 已安装插件 > ShopAGG App Store > 启用`
3. 启用后在左侧菜单会出现：
	 `App Store`

## 配置说明

### 1) API 服务地址

当前插件在主文件中使用固定常量：

- `SHOPAGG_APP_STORE_API_URL = http://new-shopagg.local/api/shopagg-app-store/`

生产环境请修改为正式 API 地址，位置：

- `shopagg-app-store.php`

### 2) 获取 API Token

在 ShopAGG 控制台生成 API Token，然后在 WordPress 后台 `App Store` 页面输入并连接。

连接成功后会存储：

- `shopagg_app_store_access_token`
- `shopagg_app_store_user`

停用插件时会清理以上两项数据。

## 使用流程

1. 打开后台菜单 `App Store`
2. 输入 API Token 并连接
3. 在 `Plugins` / `Themes` 标签中浏览资源
4. 进入资源详情页：
	 - 免费资源：点击 Install
	 - 付费资源：先 Purchase，再 Install
5. 安装完成后，资源会登记到受管列表，用于后续更新检测

## 更新机制说明

插件通过以下方式接入 WordPress 更新体系：

- 插件更新过滤器：`pre_set_site_transient_update_plugins`
- 主题更新过滤器：`pre_set_site_transient_update_themes`
- 详情弹窗信息：`plugins_api`

受管资源信息存储在：

- `shopagg_app_store_managed_resources`

仅通过本插件安装并完成登记的资源，才会被纳入 ShopAGG 更新检测。

## 安全与权限

- 所有核心 AJAX 操作都使用 nonce 校验
- 登录/购买等操作要求 `manage_options`
- 安装操作要求 `install_plugins`
- Token 失效（401）时会自动清除本地登录信息

## 当前实现说明（MVP）

- 购买流程为 MVP：创建订单后直接调用支付接口模拟支付
- Token 登录方式为手动粘贴，不含 OAuth 流程
- API 地址目前为代码内常量，尚未提供后台配置页

## 常见问题

### 1) 无法连接 API

- 检查 API 地址是否可达
- 检查 Token 是否正确、是否过期
- 检查服务器是否能访问 API 服务

### 2) 安装失败

- 确认当前账户有插件/主题安装权限
- 确认目标资源对应下载链接可访问
- 检查 WordPress 文件写入权限（插件目录/主题目录）

### 3) 看不到更新

- 仅受管资源支持自动检测更新
- 确保该资源是通过本插件安装
- 确保当前站点仍处于登录状态且 Token 有效

## 目录结构

- `shopagg-app-store.php`：插件入口、菜单、资源加载
- `includes/class-api-client.php`：API 请求封装
- `includes/class-auth.php`：Token 登录与登出
- `includes/class-market.php`：商店页、详情页、购买安装入口
- `includes/class-installer.php`：资源下载安装
- `includes/class-updater.php`：更新系统对接
- `includes/class-license.php`：授权校验
- `assets/js/app.js`：后台交互逻辑
- `assets/css/style.css`：后台样式

## 开发建议

后续可优先迭代：

- 增加后台设置页（可配置 API 地址）
- 接入真实支付与订单状态轮询
- 增加安装后自动激活开关
- 增加多语言文案与国际化完善

