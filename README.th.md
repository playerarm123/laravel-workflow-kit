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

| | |
|---|---|
| PHP | 8.4 |
| Framework | Laravel 13, Inertia 3 กับ React 19, Wayfinder |
| Test และ analysis | Pest 5, Larastan 3, ESLint 9, Prettier 3 |
| Database | PostgreSQL, MySQL หรือ MariaDB โดย `.env.example` กับ `phpunit.xml` ต้องใช้ตัวเดียวกัน (ห้ามใช้ sqlite) |
| Agents | Laravel Boost 2 สำหรับรับ guidelines และ skills |

รายการ stack ทั้งหมดที่ล็อกเวอร์ชันไว้อยู่ใน [`stack.md`](resources/boost/guidelines/stack.md) และ Architecture suite เป็นตัวตรวจ

## ติดตั้ง

### 1. Require package

```bash
composer require --dev playerarm123/laravel-workflow-kit:^0.1
```

Laravel ค้นเจอ `WorkflowKitServiceProvider` เองอัตโนมัติ provider นี้ลงทะเบียนคำสั่ง `kit:*` และ `make:*` และเข้าไปแทน `make:controller`, `make:enum` และ `make:policy` ของ framework

### 2. ส่ง guidelines ให้ Boost

เพิ่ม package ลงใน `boost.json` แล้วให้ Boost เขียน guidelines กับ skills `list-page` / `create-page`:

```json
"packages": ["playerarm123/laravel-workflow-kit"]
```

```bash
php artisan boost:update
```

### 3. วางไฟล์ของ kit

```bash
php artisan kit:install
```

คำสั่งนี้คัดลอกไฟล์ kit จาก `resources/kit` ไปไว้ที่ path ที่กำหนด:
- `resources/kit/files/` คือไฟล์ที่โปรเจกต์ต้องเก็บไว้ให้ตรงกับต้นฉบับทุกไบต์ เช่น `app/Domain/Shared/AggregateRoot.php`, `app/Http/FlashToast.php`, `resources/js/hooks/use-data-table.tsx`, หน้า audit log และ stub ของ migration กับ model check `kit-files` จะล้มเมื่อไฟล์ไหนหายหรือเนื้อหาไม่ตรง
- `resources/kit/scaffold/` คือไฟล์ที่วางให้ครั้งเดียวแล้วโปรเจกต์เป็นเจ้าของ ตอนนี้มี `app/Application/Auth/UserContext.php` ไฟล์เดียว ซึ่งโปรเจกต์เพิ่ม method ของ role เอง

ไฟล์ที่เนื้อหาไม่ตรงจะไม่ถูกแตะ แค่แสดงรายชื่อ `kit:install --force` จะเขียนต้นฉบับของ kit ทับกลับไป ส่วนไฟล์ scaffold จะไม่ถูกเขียนทับเลย

### 4. ต่อสายเข้ากับโปรเจกต์

`kit:install` พิมพ์ขั้นตอนเหล่านี้ทุกครั้งที่วางไฟล์ใหม่ และ check จะฟ้องจนกว่าจะทำครบ

**Providers** ใน `bootstrap/providers.php`:

```php
App\Providers\KitServiceProvider::class,   // หน้าจอ /kit/structure และ /kit/docs บนเครื่อง local เท่านั้น
App\Infra\Audit\AuditServiceProvider::class,
```

**Exceptions** ([exceptions.md](resources/boost/guidelines/exceptions.md)) เรียกใน `bootstrap/app.php`:

```php
->withExceptions(function (Exceptions $exceptions): void {
    ExceptionResponses::register($exceptions);
})
```

แล้วเรียกใน `boot()` ของ provider:

```php
Inertia::handleExceptionsUsing(ExceptionResponses::respond(...));
```

**Ports ที่โปรเจกต์ต้องทำเอง** ([handlers.md](resources/boost/guidelines/handlers.md)):
- bind `App\Domain\Shared\Ports\IdGenerator` เข้ากับ adapter ใน `$bindings` ของ provider เช่นตัวสร้าง UUIDv7
- bind `App\Application\Auth\UserContext` ราย request ใน middleware และในตัวเดียวกันให้เรียก `Context::add('actor_id', $user?->getAuthIdentifier())` เพื่อให้ audit log และทุกบรรทัด log รู้ว่าใครเป็นคนทำ

**Shared props และ toast** ([list-pages.md](resources/boost/guidelines/list-pages.md), [form-pages.md](resources/boost/guidelines/form-pages.md)):
- share `locale`, `timezone`, `currency` และ `translations` จาก `HandleInertiaRequests::share()`
- render `<FlashToast />` ไว้ข้าง `<Toaster />` ของ sonner ใน `app.tsx`

**Tests** ใน `phpunit.xml`:

```xml
<testsuite name="Architecture">
    <directory>vendor/playerarm123/laravel-workflow-kit/tests/Architecture</directory>
</testsuite>
```

**ESLint** ใน `eslint.config.js`:

```js
import actions from './vendor/playerarm123/laravel-workflow-kit/tests/ESLint/actions.js';
import dates from './vendor/playerarm123/laravel-workflow-kit/tests/ESLint/dates.js';
import formPages from './vendor/playerarm123/laravel-workflow-kit/tests/ESLint/form-pages.js';
import listPages from './vendor/playerarm123/laravel-workflow-kit/tests/ESLint/list-pages.js';
import numbers from './vendor/playerarm123/laravel-workflow-kit/tests/ESLint/numbers.js';

export default [
    // …config ของโปรเจกต์
    ...listPages,
    ...formPages,
    ...actions,
    ...dates,
    ...numbers,
    { ignores: ['**/tests/ESLint/Fixtures/**'] },
];
```

**PHPStan** ใน `phpstan.neon`:

```neon
includes:
    - vendor/playerarm123/laravel-workflow-kit/tests/PHPStan/write-path.php
```

**หน้า audit log** ([audit-log.md](resources/boost/guidelines/audit-log.md)) kit ให้หน้ามาแล้ว ส่วนโปรเจกต์ตัดสินใจเองว่าใครเปิดได้:
- `php artisan make:policy AuditEntry` แล้วเหลือไว้แค่ `viewAny`
- `Route::resource('audit-entries', AuditEntryController::class)->only(['index'])`
- `auditLogReader()` และ `auditLogOutsider()` ใน `tests/Pest.php`
- keys ของหน้านี้ในทุก `lang/*.json`

**Marker สำหรับ `kit:apply`** วาง `// kit:bindings` เป็นบรรทัดสุดท้ายใน `$bindings` ของ provider ตัวหนึ่ง และวาง `// kit:routes` เป็นบรรทัดสุดท้ายของกลุ่ม route ที่หน้าใหม่ควรไปอยู่

### 5. ตรวจ

```bash
php artisan test --testsuite=Architecture
```

ทุกจุดที่ล้มจะขึ้นเป็น `[rule:check] subject: message — see rule.md in the workflow kit's guidelines` ให้แก้ตามที่บอกแล้วรันใหม่จนผ่าน

## ใช้งานเร็ว

### ออกแบบก่อน แล้วค่อยสร้าง

```bash
php artisan kit:import        # เขียน .kit/structure/*.json จากโค้ดที่มีอยู่
                              # ออกแบบ: แก้ JSON หรือเปิด /kit/structure บนเครื่อง local
php artisan kit:plan          # แสดงขั้นตอน: เสร็จแล้ว, พร้อมรัน หรือรออะไรอยู่
php artisan kit:apply         # รันทุกขั้นที่พร้อม: generators และบรรทัด binding กับ route
```

`kit:apply` จะหยุดตรงที่ต้องให้คนเขียนต่อ เช่น fields ของ Command, keys ของ Row หรือเนื้อของ method เขียนส่วนนั้นแล้วรันใหม่ มันจะทำต่อจากจุดที่หยุดไว้

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
| `make:domain-service {name} --domain= --creates=\|--data\|--plain` | domain service ในหนึ่งในสามรูปแบบ |
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

## License

MIT ดู [LICENSE](LICENSE)

## อ่านเพิ่ม

- guidelines ทั้งหมดอยู่ใน [`resources/boost/guidelines`](resources/boost/guidelines) บนเครื่อง local เปิดดูที่ `/kit/docs` ได้ ซึ่งแสดงคำสั่งทั้งหมดของ console ไว้ด้วย
- หน้าจอออกแบบ: `/kit/structure` ใช้ได้บนเครื่อง local เท่านั้น ([structure.md](resources/boost/guidelines/structure.md))
