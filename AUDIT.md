# everpsclickandcollect – 发布前生命周期安全审计

- 审计对象：分支 `pickup-now-later`，提交 `a066f56`（模块版本 3.4.0）
- 审计日期：2026-10-09
- 范围：安装、重复安装、升级（含重复升级）、降级、禁用、卸载、重新安装、重置、升级失败恢复、安装失败重试，以及所有 `CREATE / ALTER / DROP / DELETE / TRUNCATE / REPLACE` 数据库操作。
- 本文件分两部分：**第一部分是修复前的审计结果（本次提交）**；第二部分（修复后的复测和发布检查清单）在修复完成后追加。

## 0. 测试环境与安全措施

| 项目 | 内容 |
|---|---|
| PrestaShop | 8.2.0（官方源码，classic 主题），独立副本 `shop_lc`，数据库 **`ps_lc`** |
| PHP / 数据库 | PHP 8.3.6 CLI，MariaDB 10.11 |
| 生产环境 | **没有在任何生产环境执行任何测试。** 所有破坏性操作只在 `ps_lc` 上执行 |
| 防护 | 测试工具在 CLI 以外直接退出；`LC_DB` 必须以 `_lc` 结尾且与店铺实际数据库一致，否则拒绝运行；每个场景前从 dump 重建 `ps_lc` |
| 测试方式 | PrestaShop 自带命令 `bin/console prestashop:module install/upgrade/uninstall/reset/enable/disable --env=prod`（与后台“模块管理”走同一个 `ModuleManager` 代码）；故障注入通过收回 MySQL 用户在 `ps_lc` 上的某个权限实现 |
| 历史版本 | 3.1.1 = `588f834`（Team Ever 原版），3.2.0 = `671efd2`，3.3.0 = `c68a1cb` |
| 自动化 | `tests/lifecycle/run.py`（16 个场景，81 项检查），工具在 `tests/lifecycle/php/`，原始结果 `tests/lifecycle/results/before_fix_ps8.2.0.json` |

每项检查都按“**安全的预期行为**”编写：FAIL 表示风险被实际复现。

修复前结果（PrestaShop 8.2.0）：**81 项检查，52 PASS，29 FAIL**。

| 场景 | 内容 | 结果 |
|---|---|---|
| S01 | 全新安装 | 7/7 PASS |
| S02 | 已安装时再次调用 `install()` | 3 FAIL（多建承运商、CARRIER_ID 被改、门店行重复） |
| S03 | 禁用 / 启用 | 1 FAIL（禁用后承运商仍可下单） |
| S04 | 有历史数据时卸载，再重新安装 | 9 FAIL（取货数据、库存、配置全部删除，16 个订单失去承运商） |
| S05 | 后台“重置” | 3 FAIL（同上） |
| S06 | 后台编辑承运商 | 1 FAIL（历史订单的取货信息不再显示） |
| S07 | 3.1.1 → 3.4.0 升级（有历史） | 9/9 PASS |
| S08 | 3.2.0 → 3.4.0 升级（有历史） | 9/9 PASS |
| S09 | 重复执行所有升级脚本 | 2/2 PASS（幂等） |
| S10 | 2.x → 3.4.0 升级（模拟） | 5 FAIL（升级失败后版本号已被改，无法重试） |
| S11 | 升级中 ALTER 失败，修复权限后重试 | 1 FAIL（失败期间结账报 SQL 异常）；重试本身成功 |
| S12 | “卡住”的升级（版本已改、结构未迁移）恢复 | 2 FAIL（启用不能修复，只能“重置”=删数据） |
| S13 | 安装后期失败，再重试 | 3 FAIL（半安装状态，重试无法补齐） |
| S14 | 降级到 3.3.0 | 1 FAIL（3.4.0 订单的时间不显示，数据未丢） |
| S15 | 订单状态改 3 次（门店邮件开启） | 1 FAIL（每次新建一个地址） |
| S16 | 显式“彻底清理” | 不存在该功能 |

## 1. 所有数据库写操作清单（修复前）

| 位置 | 操作 | 对象 | 何时执行 |
|---|---|---|---|
| `sql/install.php:25-66` | `CREATE TABLE IF NOT EXISTS` | `everpsclickandcollect`、`_store_stock`、`_store` | `install()` 开头 |
| `sql/install.php:76-86` | `INSERT … REPLACE`（自增主键，实际总是新增） | `_store` 每个门店一行 | 每次 `install()` |
| `sql/uninstall.php:25` | **`DROP TABLE`** | `everpsclickandcollect`（所有订单的门店、取货日期和时间） | 每次卸载 / 重置 |
| `sql/uninstall.php:26` | **`DROP TABLE`** | `everpsclickandcollect_store_stock`（门店库存） | 每次卸载 / 重置 |
| `everpsclickandcollect.php:196-199` | **`Carrier::delete()`（硬删除）** | `carrier`、`carrier_lang`、`carrier_shop`、`module_carrier`、`cart_rule_carrier`、`carrier_tax_rules_group_shop` | 每次卸载 / 重置 |
| `everpsclickandcollect.php:200-232` | `Configuration::deleteByName` | 全部取货设置和文字（但漏掉 8 个旧键） | 每次卸载 / 重置 |
| `everpsclickandcollect.php:1107-1138` | `Carrier::add()` + 改写 `CARRIER_ID` | 新承运商 | 每次 `install()`，**在 `parent::install()` 之前** |
| `everpsclickandcollect.php:1620` | `INSERT … REPLACE` | 一行/购物车 | 结账、后台改时间 |
| `everpsclickandcollect.php:2743-2759` | `Address::save()` + `Order::update()` | 新地址，改订单配送地址 | 每次订单状态变化（门店邮件开启时） |
| `models/EverpsclickandcollectStoreStock.php:432` | `DELETE` | 某门店全部库存 | 删除门店；以及（错误地）删除商品组合时 |
| `models/EverpsclickandcollectStoreStock.php:475` | `DELETE` | 某商品全部库存 | 删除商品 |
| `controllers/admin/AdminEverPsClickAndCollectController.php:324,395` | `ObjectModel::delete()` | `_store` 一行 | 员工在后台列表点删除 |
| `upgrade/upgrade-3.2.0.php` | `ALTER … MODIFY delivery_hour text` | 主表 | 升级 |
| `upgrade/upgrade-3.4.0.php:41-45` | `ALTER … ADD`（先 `SHOW COLUMNS` 检查） | 主表 4 个新列 | 升级 |
| `upgrade/install-2.1.5.php`、`upgrade-2.3.0.php` | `CREATE TABLE IF NOT EXISTS` | 旧表 | 从 2.x 升级 |

没有任何 `TRUNCATE`；模块从不删除 `orders`、`order_*`、`store`、`stock_available` 等 PrestaShop 核心数据。

## 2. 风险清单（修复前）

严重度：**严重** = 不可恢复的数据丢失；**高** = 功能失效或需要删数据才能恢复；**中** = 可恢复的错误状态；**低** = 冗余数据或显示降级。

### R1【严重】卸载会删除全部取货历史和门店库存

- 源码：`everpsclickandcollect.php:195` → `sql/uninstall.php:25-26`（`DROP TABLE`）。
- 影响：所有订单的取货门店、取货日期、时间段、“到店备货”标记被永久删除；门店库存表被删除。重新安装后表是空的。
- 复现（S04）：安装当前版本 → 写入 16 条取货记录和 3 条库存 → `prestashop:module uninstall` → 主表和库存表 `MISSING`；重新安装后主表 0 行，后台 16 个订单都不再显示取货信息。
- 测试结果：**已复现**（`16 -> MISSING`、`3 -> MISSING`、`before [6,7,8,9] / after []`）。
- 修复方案：卸载只注销模块（钩子、后台页面），**保留表、配置和数据**；删除数据改为单独的、需要明确确认的“彻底清理”操作（R16）。

### R2【严重】卸载硬删除承运商，历史订单失去承运商

- 源码：`everpsclickandcollect.php:196-199` `(new Carrier(CARRIER_ID))->delete()`；PS 8.2 `classes/Carrier.php:256` `delete()` = `ObjectModel::delete()` 物理删除 + `module_carrier`、`cart_rule_carrier`。
- 影响：订单 `id_carrier` 指向不存在的行；后台订单页“承运商”栏为空（实测）；按承运商统计 / 导出的订单丢失归属；购物车规则与该承运商的关联被删除。
- 复现（S04）：卸载后 `orders_with_missing_carrier` 从 0 变为 16。在复制的店铺里还发现开发期间留下的 1 个订单（id 6）指向已被删除的承运商 5，就是这样造成的。后台订单 7 的承运商栏为空（Playwright 实测）。
- 测试结果：**已复现**。
- 修复方案：卸载只把承运商设为停用（`active = 0`），保留记录；重新安装时复用原承运商；“彻底清理”时也只做软删除（`deleted = 1`，与后台删除承运商的做法相同），永远不硬删除。

### R3【高】后台“重置”= 卸载 + 安装，同样删除全部数据

- 源码：PS 8.2 `src/Core/Module/ModuleManager.php:317-322`（模块没有 `reset()` 方法时执行 `uninstall && install`）。
- 复现（S05）：`prestashop:module reset` 后主表 16 → 0，16 个订单失去承运商。
- 测试结果：**已复现**。
- 修复方案：随 R1、R2 一起修复（卸载不再删除数据，重置自然安全）。

### R4【高】卸载删除全部设置，重新安装后恢复默认值

- 源码：`everpsclickandcollect.php:200-232`。另有 8 个旧键（`_DEFAULT_STORE、_IMG、_MAIL、_MSG、_STOCK、_STORES_IDS、_TAB、_VALID_STATES`）不删除，卸载后状态不一致。
- 复现（S04）：自定义的 T2 文字和取货时间表在卸载后消失，重装后变成默认值。
- 测试结果：**已复现**。
- 修复方案：卸载保留全部设置；“彻底清理”删除所有 `EVERPSCLICKANDCOLLECT_%` 键。

### R5【高】后台编辑承运商后，所有历史订单不再显示取货信息

- 源码：判断“是否为本模块订单”只比较 `order.id_carrier == CARRIER_ID`：`everpsclickandcollect.php:1961`（邮件）、`2001`（确认页/发货单）、`2042`（后台订单页）、`2139`（订单列表列和日期筛选）、`2415`（门店邮件和门店地址）。PS 8.2 `AdminCarrierWizardController.php:795-823`：编辑承运商会复制出新 id、旧的设 `deleted=1`，`hookActionCarrierUpdate` 只把 `CARRIER_ID` 改成新 id。
- 影响：编辑一次承运商（哪怕只改名称或价格）后，之前所有订单的取货信息从后台订单页、订单列表、发货单、邮件中消失（数据仍在数据库里）。
- 复现（S06）：按 PS 核心代码重放一次编辑 → 订单 6-9 的后台取货块全部为空。
- 测试结果：**已复现**（`before [6,7,8,9] / after []`）。
- 修复方案：按承运商的 `external_module_name = 'everpsclickandcollect'` 识别本模块的所有承运商（包括已被编辑/软删除的旧 id），不再只比较当前 `CARRIER_ID`。

### R6【高】升级脚本返回 false 时，PrestaShop 仍记录新版本号，迁移再也不会执行

- 源码：PS 8.2 `src/Core/Module/ModuleManager.php:375-379`（PS 9.2 `:404-408` 相同）：`runUpgradeModule()` 之后**无条件** `upgradeModuleVersion($name, $module->version)`。模块侧：`upgrade/install-2.1.5.php` 和 `upgrade/upgrade-2.3.0.php` 中 `$result = false; $result &= …` **永远返回 false**。
- 影响：任何从 2.3.0 以前版本升级的店铺：2.3.0 脚本“失败” → 模块被停用 → 但版本号已写成 3.4.0 → 3.2.0 / 3.3.0 / 3.4.0 的迁移全部跳过；再次升级显示“成功”但什么也不做；手动启用后结账抛出 `Unknown column 'pickup_mode'`；承运商在此期间一直可被选择（R8）。唯一的“恢复”方式是重置 = 删数据（R3）。
- 复现（S10）：安装 3.1.1 并写入历史 → 把版本标记为 2.2.0（**模拟**旧安装，3.1.1 与 2.x 的主表结构相同，但 2.x 的真实安装包没有测试）→ 升级 → `To prevent any problem, this module has been turned off`，版本 3.4.0，表结构仍是旧的；重试升级无效；启用后保存取货选择抛出 SQL 异常。
- 测试结果：**已复现**（模拟的 2.x 起点）。
- 修复方案：两个旧脚本按真实结果返回；所有迁移收拢到一个幂等的“修复（repair）”流程，在升级、启用和打开模块配置页时都会检查并补齐结构，因此即使 PS 已改了版本号，启用模块即可恢复，不需要删数据。

### R7【中】升级中途 SQL 失败：新代码在旧结构上运行，结账报错

- 源码：`upgrade/upgrade-3.4.0.php:41-45`（`ALTER` 结果未检查，PS 8 的 PDO 遇错抛异常）；`everpsclickandcollect.php:1620` `savePickupChoice()` 不捕获异常。
- 复现（S11）：从 3.1.1 升级时收回 `ps_everpsclickandcollect` 的 `ALTER` 权限 → 升级报错，版本仍是 3.1.1（可以重试，**这一点是安全的**），没有数据丢失；但模块保持启用，新代码在旧表上运行，保存取货选择时抛出 `Unknown column 'pickup_mode'`（店铺前台在结账一步会出现 500 错误）。恢复权限后重试升级成功。
- 测试结果：失败期间结账异常**已复现**；重试**通过**。
- 修复方案：保存取货选择时捕获数据库异常，记录日志并提示顾客暂时无法使用该配送方式，不再抛出 500；结构检查同 R6。

### R8【中】禁用模块后，承运商仍然可以下单

- 源码：`Module::disable()` 不处理承运商；PS 8.2 `Cart::getPackageShippingCostFromModule()` 对已禁用的模块仍返回运费。
- 影响：模块禁用期间顾客仍能选择“到店自取”，但门店、取货时间的选择和校验钩子都不执行，订单没有任何取货信息。
- 复现（S03）：禁用后 `getDeliveryOptionList()` 仍包含模块承运商。
- 测试结果：**已复现**。
- 修复方案：`disable()` 时停用承运商，`enable()` 时重新启用。

### R9【中】重复调用 `install()` 或安装后期失败：多建承运商、半安装状态无法通过重试修复

- 源码：`everpsclickandcollect.php:59-64`：`sql/install.php` 和 `addCarrier()` 在 `parent::install()`（检查“已安装”）**之前**执行；`addCarrier()` 总是新建并改写 `CARRIER_ID`；之后任一步失败都没有回滚。
- 复现：
  - S02：已安装时直接调用 `install()` → 返回 false，但多出一个承运商，`CARRIER_ID` 7 → 8（旧订单从此不再被识别，同 R5），`_store` 行 5 → 10。（通过后台“模块管理”或命令行重复安装时 PS 会改走升级，没有影响。）
  - S13：收回 `ps_tab` 的 `INSERT` 权限后安装 → 报错，但模块已登记为“已安装、已启用”，承运商已启用，后台“修改取货时间”页面缺失；恢复权限后重试安装 = 空升级，页面仍然缺失。唯一办法是重置（在有数据时 = R3）。
- 测试结果：**已复现**。
- 修复方案：先 `parent::install()`，再按顺序执行各个幂等步骤；任何一步失败就回滚模块登记（不删数据），并停用承运商；承运商只在不存在时创建，存在时复用。

### R10【低】每次安装都会重复插入 `_store` 门店行

- 源码：`sql/install.php:76-86`（`REPLACE` 遇到自增主键等于普通插入）。
- 复现：S02 / S04：5 → 10 行。
- 测试结果：**已复现**。
- 修复方案：只为还没有记录的门店插入。

### R11【低】降级到 3.3.0 后，3.4.0 订单的取货时间不显示

- 源码：`everpsclickandcollect.php:1603-1618`：3.4.0 只写新列，`delivery_hour` 为 NULL；旧版本只读 `delivery_hour`。
- 复现（S14）：用 3.4.0 保存取货时间 → 换回 3.3.0 代码 → 后台只显示门店，没有时间。数据没有丢失，回到 3.4.0 后恢复显示。PrestaShop 本身不支持模块降级（不会执行任何脚本）。
- 测试结果：**已复现**（仅显示问题）。
- 修复方案：同时以 3.3.0 的格式写 `delivery_hour`（`YYYY-MM-DD HH:MM-HH:MM,…`），新版本不读这个字段。

### R12【中】订单状态每变化一次就新建一个门店地址

- 源码：`everpsclickandcollect.php:2392-2398` `hookActionOrderStatusUpdate` → `hookActionValidateOrder` → `2438` `createStoreAddressForCustomer()`，没有“已设置过”的判断。
- 复现（S15）：开启门店邮件后连续改 3 次状态 → `ps_address` 52 → 55，订单的配送地址每次被换成新的一行。
- 测试结果：**已复现**。
- 修复方案：订单的配送地址已经是该门店地址时不再新建。

### R13【潜在】删除商品组合的钩子会删除整个门店的库存

- 源码：`everpsclickandcollect.php:2385-2390` 把组合 id 当作门店 id 传给 `dropStoreStock()`。
- 现状：该钩子从未注册（3.1.1 起都没有），所以**当前不会执行**；如果以后有人注册它，会删除 id 等于组合 id 的门店的全部库存。
- 测试结果：**未执行**（钩子未注册，只做了静态确认）。
- 修复方案：改为只删除该组合的库存行。

### R14【中】声明的兼容版本过宽

- 源码：`everpsclickandcollect.php:50` `ps_versions_compliancy = ['min' => '1.7', 'max' => _PS_VERSION_]`。`max = _PS_VERSION_` 等于“兼容任何未来版本”（包括 PS 10）；`min = 1.7` 包含 1.7.0-1.7.6，而模块使用的 `displayAdminOrderMain`、订单列表 Grid 钩子从 1.7.7 才有。
- 实测范围：PrestaShop **8.2.0** 与 **9.2.0**；PHP **8.3** 运行时。PHP 7.2-8.2、8.4 只做过静态检查（PHPCompatibility + 人工），**未验证**；PS 8.0.x、8.1.x、9.0.x、9.1.x **未验证**；1.7.x **不支持**。
- 修复方案：`min 8.0.0`、`max 9.99.99`；README 写明实测与未实测版本。

### R15【低】升级在 PS 8/9 上本身是“部分安全”的（记录现状）

- 3.1.1 → 3.4.0、3.2.0 → 3.4.0：全部通过（S07、S08），数据、钩子、承运商都正确。
- 重复执行全部升级脚本：返回 true，数据库无任何变化（S09）。
- 升级中 SQL 抛异常：PS 不改版本号，修好原因后重试成功（S11）。
- 风险集中在“脚本返回 false”（R6）和“失败期间新代码运行”（R7）。

### R16【中】没有“彻底清理”的独立入口

- 现状：删除数据和卸载绑在一起，无法“只卸载”或“只清理”。
- 修复方案：配置页加一个“删除全部模块数据并卸载”的操作，必须勾选确认并输入 `DELETE`；删除模块表和设置，承运商只软删除，订单不动。

## 3. 五个问题的回答（修复前）

1. **是否可能删除历史订单、自提日期、取货时间、门店或库存数据？**
   订单本身（`ps_orders` 等）和 PrestaShop 门店（`ps_store`）不会被删除。但**取货门店、日期、时间会在卸载或重置时被删除（R1、R3）**，**门店库存会在卸载或重置时被删除（R1）**；删除门店 / 商品时删除对应库存（预期行为）；R13 是潜在的错误删除（当前未触发）。
2. **是否可能导致升级失败、重复创建对象或重复执行数据库迁移？**
   重复执行迁移是安全的（S09 通过）。会重复创建：承运商（R9）、`_store` 行（R10）、门店地址（R12）。升级失败：从 2.x 升级必定失败且无法重试（R6）；SQL 错误时可以重试，但失败期间结账报错（R7）。
3. **是否可能删除仍被订单引用的配送承运商？**
   **会。** 卸载和重置都会硬删除当前承运商，所有订单失去承运商（R2）。
4. **安装或升级中途失败后，能否安全重试？**
   升级抛异常：可以重试（S11）。升级脚本返回 false：不能（R6）。安装后期失败：不能，只能重置（R9），有数据时会删数据。
5. **是否支持当前声明的 PrestaShop 和 PHP 版本？**
   不完全。声明为 1.7 至“任何版本”，实测只有 8.2.0 和 9.2.0（R14）。

## 4. 修复原则（下一步）

只做最小范围修改，不重写业务逻辑：

- 生命周期拆成独立职责：**结构（Schema）**、**承运商（CarrierManager）**、**数据迁移（Migrator）**、**编排（Installer：install / repair / uninstall / purge / enable / disable）**。
- 卸载不删数据；删除数据只在“彻底清理”中进行，并且需要明确确认。
- 所有步骤幂等，可以重复执行；安装失败回滚模块登记，不删数据。
- 不为了通过测试而删除历史数据或跳过失败：测试脚本的预期不变，修复后重新运行同一套测试。
