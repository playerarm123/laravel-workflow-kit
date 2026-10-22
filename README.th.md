# playerarm123/laravel-workflow-kit

[English](README.md)

ชุดกฎ, generator และ check ที่ทำให้ทุกโปรเจกต์ Laravel ถูกสร้างแบบเดียวกัน ติดตั้งเป็น dev dependency ตัวเดียวแล้วได้:

- **Guidelines และ skills** สำหรับ AI agent และคน ซึ่ง [Laravel Boost](https://github.com/laravel/boost) เขียนลง `CLAUDE.md` / `AGENTS.md`
- **Generators** (`make:*`) ที่เขียนแต่ละชิ้นในรูปที่ guidelines กำหนด
- **Structure manifest** (`kit:import`, `kit:plan`, `kit:apply`, `kit:retire`) พร้อมหน้าจอสำหรับออกแบบบนเครื่อง local
- **Checks** ที่บังคับให้โค้ดตรงตามกฎ ได้แก่ test suite `Architecture`, กฎ ESLint และกฎ PHPStan
- **ไฟล์ kit ในโปรเจกต์** (base classes, hooks ของหน้า list และ form, หน้า audit log ฯลฯ) ที่ `kit:install` วางให้ และต้องตรงกับต้นฉบับของ kit เสมอ

ตัวกฎอยู่ใน [`resources/boost/guidelines`](resources/boost/guidelines) ไฟล์นี้บอกแค่วิธีติดตั้งและวิธีใช้ในงานประจำวัน

## สิ่งที่ต้องมี

- PHP 8.4, Composer และ Node 22.18 ขึ้นไป หรือ 24.11 ขึ้นไป (ช่วงที่ `vite-plus` ของ starter kit ต้องการ ถ้าเป็น 22 ที่เก่ากว่านี้ npm จะเตือน `EBADENGINE`)
- เซิร์ฟเวอร์ PostgreSQL, MySQL หรือ MariaDB อย่างใดอย่างหนึ่ง kit ไม่รองรับ sqlite เพราะเทสต์ต้องรันบน engine เดียวกับ production
- โปรเจกต์ที่สร้างจาก React starter kit ของ Laravel (Laravel 13, Inertia 3, React 19) ส่วน stack ที่เหลือ `kit:setup` จะจัดให้ตาม [`stack.md`](resources/boost/guidelines/stack.md) ซึ่ง Architecture suite ใช้ตรวจ
- starter kit แบบไม่มี teams ถ้าโปรเจกต์สร้างจากแบบที่มี teams `kit:setup` จะหยุดก่อนแก้อะไรทั้งสิ้น เพราะ migration ของแบบนั้นผูกสมาชิกทีมกับ `users.id` ที่เป็น bigint ขณะที่ kit ใช้ uuid และโค้ดส่วนทีมก็ไม่ได้เขียนตามกฎของ kit ถ้าโปรเจกต์ต้องมีทีม ให้สร้างเองด้วย kit

## ติดตั้ง

โปรเจกต์ใหม่ใช้คำสั่งเหล่านี้:

```bash
laravel new my-app --react          # เลือก test framework และ package manager แบบไหนก็ได้
cd my-app
composer require --dev playerarm123/laravel-workflow-kit
php artisan kit:setup               # จะถามว่าใช้ฐานข้อมูลอะไร: pgsql, mysql หรือ mariadb
php artisan migrate:fresh           # หลังสร้างฐานข้อมูลตามชื่อใน .env แล้ว
php artisan test --testsuite=Architecture
```

`kit:setup` ใช้เวลาไม่กี่นาที เพราะต้องติดตั้งแพ็กเกจ จากนั้นเหลือสามอย่างที่ต้องทำเอง:

1. รัน `php artisan boost:install` แล้วเพิ่ม `"playerarm123/laravel-workflow-kit"` ใน `"packages"` ของ `boost.json` เพื่อให้ agent ได้ guidelines และ skill `list-page` / `create-page`
2. รัน `npx playwright install chromium` สำหรับ Browser tests
3. กำหนดว่าใครเปิดดู audit log ได้ ค่าเริ่มต้นใน `app/Policies/AuditEntryPolicy.php` ให้ทุกคนที่ยืนยันอีเมลแล้วเปิดได้ ถ้าแก้ policy ต้องแก้ `auditLogReader()` / `auditLogOutsider()` ใน `tests/Pest.php` ให้ตรงกันด้วย

### `kit:setup` ทำอะไรบ้าง

แก้เฉพาะส่วนที่ยังไม่ตรงตามที่ kit ต้องการ จึงรันซ้ำได้ทุกเมื่อ รันรอบที่สองจะไม่เปลี่ยนอะไรเลย ถ้าเจอไฟล์ที่หน้าตาไม่เป็นอย่างที่คาด จะไม่แตะไฟล์นั้นและแจ้งไว้ใต้หัวข้อ *Left to do by hand*

| ขั้น | สิ่งที่แก้ |
|---|---|
| Composer packages | ตั้ง `php` เป็น `^8.4`, เอา PHPUnit ออกแล้วใส่ Pest 5 (พร้อม plugin Laravel และ Browser), ใส่ Boost, Nightwatch, spatie/laravel-data และ s3 driver แล้วรัน `composer update` |
| JavaScript packages | เปลี่ยน `@radix-ui/*` เป็น `radix-ui` (แก้ import ให้ทุกไฟล์), ใส่ ESLint, Prettier, Playwright และไลบรารีตาราง วันที่ และไดอะแกรม, เพิ่ม script `lint` และ `format`, ให้ script `check` และ `check:fix` ของ starter kit รัน Prettier กับ ESLint แทน `vp check` ซึ่งจัด format และ lint ไม่ตรงกับสองตัวนี้, ลบ `pnpm-workspace.yaml` ที่ starter kit ให้มาแบบ package เดียวเมื่อโปรเจกต์ใช้ npm หรือ bun แล้วรัน install ด้วย package manager ที่โปรเจกต์ใช้ |
| Kit files | ไฟล์ทุกตัวที่ `kit:install` เขียน และไฟล์ที่เขียนให้ครั้งเดียวแล้วเป็นของโปรเจกต์: `IdGenerator` แบบ UUIDv7 พร้อม provider, middleware `InitializeUserContext`, `AuditEntryPolicy`, `tests/Pest.php`, `eslint.config.js`, `.prettierrc`, type `SharedProps`, เมนูหลัก `app/Http/Navigation.php`, shadcn component สี่ตัวที่หน้าของ kit ใช้, เทสต์ของ middleware ใน starter kit และ `rule-overrides.json` เปล่า ส่วน `ExampleTest` ถูกลบออก |
| Config | ฐานข้อมูลใน `.env.example` และ `phpunit.xml` และใน `.env` ถ้ายังไม่ได้ใช้ engine นั้น (ถ้าใช้อยู่แล้วจะเติมเฉพาะ key ที่ขาด), `NIGHTWATCH_TOKEN`, Browser และ Architecture suite, กฎ write-path ของ PHPStan, `app.currency`, Vite entry ของหน้า structure และ `.github/workflows/tests.yml` ของ starter kit ซึ่งได้ service ของ engine นั้น ขั้นสร้างฐานข้อมูล `testing` และขั้นติดตั้ง Chromium ของ Playwright และรันบน PHP 8.4 |
| Wiring | providers, `ExceptionResponses`, `InitializeUserContext`, props ที่ส่งไปทุกหน้า (`locale`, `timezone`, `currency`, `translations`, `menu`) ทั้งฝั่ง PHP และ TypeScript, ให้ sidebar กับ header วาดเมนูที่ส่งมาผ่าน `useMainNavItems()` ด้วย `MainNavSidebar` / `MainNavHeader` ของ kit (กลุ่มเป็นหัวข้อพับได้ใน sidebar และเป็น dropdown ใน header), ย้าย `Navigation.php` ที่สร้างก่อนมีกลุ่มเมนูไปใช้ trait `BuildsMainMenu` และ `<FlashToast />` |
| Users on uuids | ตาราง users พร้อม sessions และ passkeys, `User`, `UserFactory` และ `CreateNewUser` (เมื่อเปิด registration) เปลี่ยนเป็น key แบบ uuid ขั้นนี้แก้ migration เดิมตรง ๆ จึงทำได้เฉพาะก่อน deploy ครั้งแรก |
| Audit log page | route, marker `// kit:routes` และคำแปลทุกคำที่หน้าของ kit ใช้ ในทุกไฟล์ `lang/*.json` และในไฟล์ของ `APP_LOCALE` กับ `APP_FALLBACK_LOCALE` ภาษาที่ไม่ใช่ `en` จะได้คำภาษาอังกฤษไปก่อน และถูกแจ้งไว้ให้แปล |

สุดท้ายรัน Pint กับไฟล์ PHP ที่แก้, `wayfinder:generate`, `lint` (ESLint แบบ `--fix`), `build` ของ package manager ซึ่ง Feature test ของหน้าใน kit ต้องใช้ และ `kit:import` หัวข้อ *Next* จะแสดงเฉพาะสิ่งที่ยังไม่ได้ทำ ถ้า build, migrate, `boost.json` หรือ Chromium เรียบร้อยแล้วจะไม่แสดงอีก

ใส่ `--database=pgsql` เพื่อข้ามคำถาม ส่วน `--skip-dependencies` จะแก้ `composer.json` และ `package.json` โดยไม่รัน install

### ใช้กับโปรเจกต์ที่มีอยู่แล้ว

`kit:setup` เขียนมาสำหรับโปรเจกต์ที่เพิ่งสร้างจาก starter kit ถ้าเป็นโปรเจกต์เก่า ให้ commit ก่อน แล้วรันและดู diff การแก้แต่ละจุดเล็กและตรงกับตารางด้านบน จุดไหนวางเองไม่ได้จะแจ้งไว้ ขั้นตอนแบบทำเองอยู่ในหัวข้อ Kit files ของ [guidelines แต่ละไฟล์](resources/boost/guidelines) และ check ที่ไม่ผ่านจะบอกเองว่าต้องการอะไร

### เมื่อ check ไม่ผ่าน

ข้อความ error จะอยู่ในรูป `[rule:check] subject: message — see rule.md in the workflow kit's guidelines` ที่เจอบ่อยในโปรเจกต์ใหม่:

| ข้อความ | วิธีแก้ |
|---|---|
| `[stack:major] x: is declared but missing from composer.lock` (หรือ lockfile ฝั่ง JS) | รัน `composer update` หรือ install ด้วย package manager ของโปรเจกต์ |
| `[stack:required] x: is required but not declared` | รัน `php artisan kit:setup` อีกรอบ หรือ require แพ็กเกจนั้นเอง |
| `[stack:database] .env.example DB_CONNECTION: is "sqlite"` | `php artisan kit:setup --database=pgsql` |
| `[stack:ci-database] .github/workflows/x.yml: runs the tests without a postgres service` | เพิ่ม service ของ engine ให้ job นั้น ([stack.md](resources/boost/guidelines/stack.md) หัวข้อ "Database") ถ้าเป็น workflow ของ starter kit เอง `kit:setup` จะเพิ่มให้ |
| ``[stack:js-tools] package.json scripts.check: runs `vp check` …`` | เปลี่ยนเป็น `prettier --check resources/ && eslint .` ถ้าเป็น script ของ starter kit เอง `kit:setup` จะเปลี่ยนให้ |
| `[stack:forbidden] cn` หรือ `[stack:cn-import] …` หลังรัน `npx shadcn add` | เพิ่ม component ด้วย `php artisan kit:ui-add <name>` ซึ่งจะ import `cn` จาก `@/lib/utils` และไม่ติดตั้งแพ็กเกจ `cn` |
| `[list-pages:menu] resources/js/pages/x/index.tsx: has no entry …` | ใส่ `"menu"` ใน manifest ของ resource แล้วรัน `php artisan kit:apply` หรือใส่ `"menu": null` ถ้าตั้งใจไม่ให้อยู่ในเมนู ([list-pages.md](resources/boost/guidelines/list-pages.md)) |
| `[list-pages:menu-group] x.index: sits in the group 'y', which … does not declare` | เพิ่ม `$this->group('y', '{title key}', '{lucide icon}')` ใน `app/Http/Navigation.php` ตรงตำแหน่งที่อยากให้กลุ่มอยู่ ([list-pages.md](resources/boost/guidelines/list-pages.md)) |
| `[list-pages:menu-ability] x.index: asks y of Model, whose policy does not answer it` | เพิ่ม `y()` ใน policy หรือใส่ `"ability"` ในรายการเมนูของ manifest ให้เป็น ability ที่ controller ของหน้านั้นถาม แล้วรัน `php artisan kit:apply` ([list-pages.md](resources/boost/guidelines/list-pages.md)) |
| `[…:kit-files] x: differs from the kit` | `php artisan kit:install --force` เพื่อเอาไฟล์ของ kit กลับมา |
| `[testing:mirror] X: has no tests/…Test.php` | เขียนเทสต์ไว้ที่ path นั้น (testing.md) |
| `[structure:in-json] .kit/structure/X.json: is missing` | `php artisan kit:import` |
| `[audit-log:labels] …` | ใส่ label ให้ event, subject หรือ key ในทุก `lang/*.json` (audit-log.md) |

ถ้ากฎข้อไหนทำตามไม่ได้จริง ๆ เจ้าของโปรเจกต์บันทึกข้อยกเว้นไว้ใน `rule-overrides.json` ([stack.md](resources/boost/guidelines/stack.md)) agent ห้ามเพิ่มเอง

## ใช้งานเร็ว

### ออกแบบก่อน แล้วค่อยสร้าง

```bash
php artisan kit:import        # เขียน .kit/structure/*.json จากโค้ดที่มีอยู่
                              # ออกแบบ: แก้ JSON หรือเปิด /kit/structure บนเครื่อง local
php artisan kit:plan          # แสดงขั้นตอน: เสร็จแล้ว, พร้อมรัน หรือรออะไรอยู่
php artisan kit:apply         # รันทุกขั้นที่พร้อม: generators และบรรทัด binding กับ route
```

`kit:apply` จะหยุดตรงที่ต้องให้คนเขียนต่อ เช่น fields ของ Command, keys ของ Row หรือเนื้อของ method เขียนส่วนนั้นแล้วรันใหม่ มันจะทำต่อจากจุดที่หยุดไว้

คู่มือหน้าจอออกแบบแบบทำตามทีละขั้นพร้อมภาพหน้าจอ: [docs/structure-screen.th.md](docs/structure-screen.th.md) (เปิดในแอปได้ที่ `/kit/docs/guides/structure-screen?lang=th`) ส่วน domain service ตั้งแต่เลือกว่าจะใช้หรือไม่ไปจนถึงเทสต์ มีคู่มือแยกที่ [docs/domain-services.th.md](docs/domain-services.th.md) (`/kit/docs/guides/domain-services?lang=th`)

ถ้าจะเปลี่ยนชิ้นที่สร้างไปแล้ว ให้ทำเครื่องหมายชิ้นใหม่ด้วย `replaces` บนหน้าจอหรือใน manifest แล้ว `kit:apply` จะสร้างชิ้นใหม่และสลับเข้าไปแทน จากนั้น `php artisan kit:retire` จะลบชิ้นเก่าออกเมื่อ suite ผ่านหมด ([structure.md](resources/boost/guidelines/structure.md))

### หรือ scaffold ทีละชิ้น

action ทีละแถวพร้อมแบบ bulk ([actions.md](resources/boost/guidelines/actions.md)):

```bash
php artisan make:use-case CancelLotteryDraw --domain=LotteryDraw --command --repo=LotteryDraw
# กรอก Command: ids และ fields ของ action เอง โดย handler คืนค่าเป็น int
php artisan make:action Cancel --model=LotteryDraw --domain=LotteryDraw
php artisan make:action Cancel --model=LotteryDraw --domain=LotteryDraw --bulk
```

หน้า list ([list-queries.md](resources/boost/guidelines/list-queries.md), [list-pages.md](resources/boost/guidelines/list-pages.md)):

```bash
php artisan make:use-case ListLotteryTypes --domain=LotteryDefinition --command --result --query
# กรอก Criteria, toArray() ของ Row และ adapter
php artisan make:controller LotteryType --domain=LotteryDefinition --only=index,destroy
php artisan make:list-page ListLotteryTypes --domain=LotteryDefinition
```

generator ทุกตัวเขียน test ไว้ข้างไฟล์ที่สร้าง โดยมี `->todo()` หนึ่งตัวต่อหนึ่ง case และพิมพ์สิ่งที่ทำเองไม่ได้ออกมาให้ เช่น บรรทัด route, policy ability ที่ยังขาด และ translation keys ที่ยังขาด

### คำสั่ง

| คำสั่ง | ทำอะไร |
|---|---|
| `kit:setup [--database=] [--skip-dependencies]` | ตั้งค่าโปรเจกต์ที่สร้างจาก React starter kit ให้ครบในครั้งเดียว |
| `kit:ui-add {components*} [--overwrite=] [--allow=] [--dry-run]` | เพิ่ม shadcn component ให้ตรงกับ stack ใช้แทน `npx shadcn add` ([stack.md](resources/boost/guidelines/stack.md)) |
| `kit:install [--force]` | วางไฟล์ของ kit ลงในโปรเจกต์ |
| `kit:import [--context=] [--resource=] [--force]` | เขียน structure manifest จากโค้ด |
| `kit:plan [--context=] [--resource=]` | แสดงขั้นตอนที่จะสร้างตาม manifest |
| `kit:apply [--context=] [--resource=]` | รันขั้นตอนเหล่านั้น |
| `kit:retire [--context=]` | ลบชิ้นเก่าที่ถูกชิ้นใหม่แทนที่เรียบร้อยแล้ว |
| `make:entity {name} --domain= [--child]` | aggregate root หรือ child entity พร้อม Unit test |
| `make:entity-method {entity} {method} --domain= [--param=] [--throws=]` | เพิ่ม behaviour หรือ assertion ให้ entity |
| `make:enum {name} --domain= --string --case= [--transition=]` | domain enum พร้อม transitions ถ้าเป็น status |
| `make:value-object {name} --domain= [--field=]` | value object พร้อม test |
| `make:domain-exception {name} --domain= --kind=refusal\|value\|application` | exception บน base class ที่ถูกต้อง |
| `make:domain-service {name} --domain= (--creates=\|--data\|--plain) [--repo=] [--exception] [--force]` | domain service ในหนึ่งในสามรูปแบบ พร้อม repository ที่ inject, exception ของตัวเอง และเทสต์ ดูตัวอย่างเต็มที่ [docs/domain-services.th.md](docs/domain-services.th.md) |
| `make:port {name} --domain=\|--application= [--adapter= --infra=]` | port และภายหลังคือ adapter พร้อม test ของ adapter |
| `make:eloquent-repository {name} --domain=` | repository, exceptions, log payload และ contract test |
| `make:use-case {name} --domain= [--command] [--result] [--repo=] [--creates] [--query]` | use case: handler, Command, Result และสำหรับ list คือ query port กับ adapter |
| `make:controller {name} --domain= --only= [--grid]` | method ของ resource controller ในรูปของ kit พร้อม test แยกทีละ action |
| `make:action {verb} --model= --domain= [--bulk] [--use-case=]` | controller, request และ test ของ action |
| `make:form-request {name} --domain=` | Store/Update requests และ form values |
| `make:form-page {name}` | form component, หน้า create/edit และ TypeScript type |
| `make:list-page {name} --domain= [--grid] [--types-only]` | หน้า list, toolbar, TypeScript types และ Browser test |
| `make:policy {model}` | policy พร้อม test |

ดู option ทั้งหมดของแต่ละคำสั่งได้ด้วย `php artisan help <command>`

### Checks

```bash
php artisan test --testsuite=Architecture   # กฎทั้งหมด ตรวจจากตัวโค้ด
vendor/bin/phpstan analyse                  # write-path: มีแค่ App\Infra ที่เขียน database ได้
npx eslint .                                # กฎของหน้าเว็บ: ตารางเดียว, ทางเดียวไป server, วันที่, ตัวเลข
```

ถ้ากฎข้อไหนใช้กับจุดใดจุดหนึ่งไม่ได้จริงๆ เจ้าของโปรเจกต์ต้องเป็นคนบันทึกข้อยกเว้นที่อนุมัติแล้วไว้ใน `rule-overrides.json` เอง ([stack.md](resources/boost/guidelines/stack.md)) agent ห้ามเพิ่มเอง

### อัปเดต kit

```bash
composer update playerarm123/laravel-workflow-kit
php artisan boost:update          # guidelines และ skills ใหม่
php artisan kit:install           # ไฟล์ kit ใหม่ และแสดงรายชื่อไฟล์ที่เนื้อหาไม่ตรง
php artisan kit:install --force   # เอาต้นฉบับของ kit ทับไฟล์เหล่านั้น
php artisan test --testsuite=Architecture
```

## ร่วมพัฒนา

kit ทดสอบตัวเองบนแอป Laravel เล็ก ๆ ใน `workbench/` ผ่าน [Orchestra Testbench](https://github.com/orchestral/testbench) ต้องมี PHP 8.4, Node 22 และฐานข้อมูล PostgreSQL ชื่อ `workflow_kit_test` บน `127.0.0.1` user `postgres`

```bash
composer install
(cd workbench && npm ci)        # ESLint และ Prettier สำหรับเทสต์ lint ของ generator
composer test                   # Feature suite: generator และเครื่องมือ structure
composer lint                   # Pint
composer analyse                # PHPStan
```

เปลี่ยนกฎข้อไหน ให้แก้ guideline ใน `resources/boost/guidelines` กับ spec ใน `tests/Architecture` ไปพร้อมกัน เปลี่ยนไฟล์ของ kit ให้แก้สำเนาใน `resources/kit` แล้วรัน `php vendor/bin/testbench kit:install --force` เพื่อวางลง workbench

CI ของ kit ยังสร้างโปรเจกต์ใหม่จาก `laravel/react-starter-kit` ติดตั้ง kit จากไฟล์ชุดเดียวกับที่ Packagist แจก แล้วรัน `kit:setup` ตามด้วย `composer ci:check` ของ starter kit เอง ได้แก่ Prettier, ESLint, tsc, Pint, PHPStan และทุก suite รวม Architecture และ Browser (job `starter-kit`) ส่วน Feature suite รัน Prettier กับไฟล์ TypeScript ของ kit ด้วย `.prettierrc` ที่ `kit:setup` เขียนและ stylesheet ของ starter kit

## License

MIT ดู [LICENSE](LICENSE)

## อ่านเพิ่ม

- guidelines ทั้งหมดอยู่ใน [`resources/boost/guidelines`](resources/boost/guidelines) บนเครื่อง local เปิดดูที่ `/kit/docs` ได้ ซึ่งแสดงคำสั่งทั้งหมดของ console ไว้ด้วย
- หน้าจอออกแบบ: `/kit/structure` ใช้ได้บนเครื่อง local เท่านั้น วิธีใช้อยู่ใน [docs/structure-screen.th.md](docs/structure-screen.th.md) ส่วนกฎที่หน้าจอยึดอยู่ใน [structure.md](resources/boost/guidelines/structure.md)
