---
name: create-page
description: "Build a create/edit form page end to end (Create{Aggregate} + Update{Aggregate} use cases, Store/Update{Aggregate}Request with toCommand(), {Aggregate}FormValues, resource controller create/store/edit/update, reusable Inertia React <Form> component + create/edit page shells, create button in the list header, Pest tests) on the form-pages rule, scaffolded by the workflow kit's make:use-case, make:form-request, make:controller and make:form-page. Activate when the user asks to create a create page, edit page, add form, new-record form, store/update endpoint, or CRUD create/edit for an aggregate; says ทำหน้าสร้าง, หน้าเพิ่ม, หน้าแก้ไข, ฟอร์มสร้าง {aggregate}, หน้า create, หน้า edit; or invokes /create-page. Starts by collecting the full form spec from the user through explicit questions — never guesses fields, validation, upload handling, or the post-save destination — then implements backend, frontend, and tests in order. Do not use for list pages (use /list-page) or detail (show) pages."
---

# Create Page

หน้าสร้าง/แก้ไข = ฟอร์มเดียวที่ใช้ทั้งสองหน้า ทางเดินของข้อมูลตาม guideline `form-pages.md`:

```
props.defaults ({X}FormValues) → <Form {...action}> → Store/Update{X}Request (authorize + rules) → toCommand() → handler → flash toast → to_route
```

skill นี้พาทำทั้งสาย backend → frontend → tests ด้วย generator ของ kit แล้วเติมส่วนที่ generator เขียนให้ไม่ได้ (enum select, ฟิลด์ที่โชว์ตามเงื่อนไข, กฎข้ามฟิลด์, ค่าที่ต้องแปลงก่อนแสดง, ไฟล์)

ถ้าโปรเจกต์มีฟอร์มที่ทำเสร็จแล้ว ให้เปิดดูหนึ่งชุดเป็นแบบ (หา `components/*/form.tsx`) — แต่สิ่งที่ generator เขียนคือความจริง ถ้าขัดกันให้เชื่อ generator และ guideline

**ขอบเขต:** `create`/`store`/`edit`/`update` + ปุ่มเข้าไปจากหน้า list + form component ตัวเดียว — ไม่แก้ `DataTable` กลางนอกจากใช้ slot `headerActions` ที่มีอยู่

กฎที่ต้องอ่านก่อนแตะไฟล์: guideline `form-pages.md` (กฎกลางที่ `FormPagesTest` + ESLint คุม), `handlers.md`, `authorization.md`, `exceptions.md`, `numbers.md`, `testing.md` และ `.ai/rules` ของโปรเจกต์ถ้ามี (อ่าน `.ai/rules/index.md` หาไฟล์ที่ตรงกับ path)

---

## ขั้น 0 — เก็บสเปกฟอร์มให้ครบก่อนแตะไฟล์ (เกตบังคับ)

**กฎ: ห้ามเดา** ทุกข้อด้านล่างต้องมีคำตอบจากผู้ใช้ หรืออ่านได้ชัดจากโค้ดที่มีอยู่ (Entity `create()` และ method ที่แก้ค่า, migration, Enum, VO, Policy) ก่อนสร้างไฟล์แรก

วิธีถาม:
- ใช้ `AskUserQuestion` ทีละชุด (สูงสุด 4 คำถามต่อครั้ง) เรียงชุด A → D
- ก่อนถามแต่ละชุด **อ่านโค้ดก่อน** แล้วเอาสิ่งที่เจอมาเป็นตัวเลือก — พารามิเตอร์ของ `{Aggregate}Entity::create()`, คอลัมน์ใน migration + unique key, enum ใน `app/Domain/**/Enums`, VO ที่ throw เมื่อค่าผิดรูป (`X::from…()`), VO ที่ถือกฎข้ามฟิลด์, method ใน Policy
- ถ้าผู้ใช้ตอบคลุมเครือ ("เอาแบบเดิม" / "ตามที่คิดว่าเหมาะ") ให้ **ถามซ้ำให้แคบลง** โดยเสนอค่าที่จะใช้จริงให้ยืนยันทีละข้อ
- ไม่ลงมือจนกว่าจะปิดครบทุกชุด แล้ว **สรุปสเปกเป็นตารางเดียวให้ผู้ใช้ยืนยันหนึ่งครั้ง** ก่อนเริ่มขั้น 1

### ชุด A — ตัวตน

- aggregate / Model / context → namespace `App\Application\{Context}`, route `{aggregates}`, lang prefix `{aggregates}.`, หน้า `pages/{aggregates}/{create,edit}.tsx`, component `components/{aggregate}/form.tsx`
- ใครสร้าง/แก้ได้ (Policy `create()`/`update()`) Policy มีอยู่แล้วหรือต้องสร้าง (`make:policy`)
- หน้า list ของ aggregate นี้มีแล้วหรือยัง (ปุ่มสร้างไปวางที่ `headerActions` ของ `<DataTable>` + `can.create` จาก controller index)

### ชุด B — ฟิลด์

- ฟิลด์ **ทีละตัว**: ชื่อ (snake_case ตรง request), มาจากพารามิเตอร์ไหนของ Command, ชนิด input (`Input` text / `type="time"` / `Select` enum / `ToggleGroup` หลายค่า / checkbox / รูป), required ไหม, ค่าตั้งต้นตอนสร้าง
- ฟิลด์ไหน **แก้ไม่ได้** ในหน้า edit (form component รับ `mode: 'create' | 'edit'` แล้วซ่อน)
- ฟิลด์ที่โชว์/ซ่อนตามฟิลด์อื่น (เช่น `days` ตาม `frequency`) — ฟิลด์ที่ไม่ใช้ในเงื่อนไขนั้นต้อง `exclude` ฝั่ง PHP
- **รูป/ไฟล์**: อัปโหลดตรงผ่าน `UploadedFile` ใน Command แล้ว handler เขียนผ่าน storage port (`form-pages.md` หัวข้อ File uploads) หรือถ้าโปรเจกต์มีคลังไฟล์ของตัวเอง ให้ถามว่าฟิลด์นี้เลือกจากคลังแทนไหม
- enum ทุกตัว → ตัวเลือกใน TS เป็นค่าคงที่พร้อม `@see` ไฟล์ PHP + คำแปล `{aggregates}.{field}.{value}` ในทุก `lang/*.json`

### ชุด C — Validation

- unique key ของตาราง (composite ไหม → `Rule::unique()->where(...)`, ฝั่ง update `->ignore(...)`)
- รูปแบบค่าที่ VO ฝั่ง domain รับ (เช่น `HH:mm` → `date_format:H:i`) — ให้ FormRequest ปฏิเสธก่อน domain throw
- ขอบเขตค่าที่ enum/VO ถืออยู่ (`minDay/maxDay`) → ดึงจาก enum ใน `rules()` ไม่เขียนเลขซ้ำ
- กฎข้ามฟิลด์ที่ domain ถืออยู่แล้ว → ตรวจใน `after()` โดย **reuse** VO เดิม และ error ไปที่ฟิลด์ไหน
- validation อยู่ที่ **FormRequest** เสมอ (ไม่ใช่ spatie Data validation, ไม่ใช่ inline ใน controller)

### ชุด D — หลังบันทึก

- redirect ไปไหน: `show` ของรายการ (default) หรือ `index`
- ข้อความ toast `{aggregates}.created` / `{aggregates}.updated` ใส่ `:name` ไหม
- ค่าที่ต้องแปลงก่อนแสดงในหน้า edit (นาที → `HH:mm`, json camelCase → ช่อง snake_case) — แปลงใน `{X}FormValues::of()` ไม่ใช่ฝั่ง TS

### สิ่งที่ตัดสินใจเองได้ ไม่ต้องถาม (แต่ต้องอยู่ในตารางสรุป)

ชื่อไฟล์/คลาสตาม convention, ลำดับฟิลด์ตามที่ผู้ใช้ไล่มา, การจัด Card ตามกลุ่มฟิลด์, คีย์คำแปลตาม prefix, ชื่อ test case/helper, การ reset ฟิลด์ลูกเมื่อฟิลด์แม่เปลี่ยน

### ตารางสรุปสเปก (ให้ผู้ใช้ยืนยันก่อนขั้น 1)

| หัวข้อ | ค่า |
| --- | --- |
| Aggregate / Context / route / lang prefix | |
| Policy / ใครสร้าง/แก้ได้ | |
| ฟิลด์ (name → input → Command param → ค่าตั้งต้น) | |
| ฟิลด์ที่แก้ไม่ได้ในหน้า edit | |
| ฟิลด์ตามเงื่อนไข | |
| รูป/ไฟล์ | |
| unique key | |
| กฎข้ามฟิลด์ (VO ที่ reuse) | |
| หลังบันทึก (redirect + toast) | |

---

## Workflow

### 1. Backend

ถ้าโปรเจกต์ใช้ structure manifest (`.kit/structure/`) ให้ออกแบบ use case, controller method และ page ลง manifest ก่อน แล้วให้ `php artisan kit:apply` รัน generator ให้ (guideline `structure.md`) — ขั้นที่ apply หยุดรอคือส่วนที่คนต้องเติม

ลำดับ:

1. `php artisan make:use-case Create{Aggregate} --domain={Context} --repo={Aggregate} --command --creates --no-interaction` (handler มินต์ id ผ่าน `IdGenerator` แล้วคืน `string`) และ `php artisan make:use-case Update{Aggregate} --domain={Context} --repo={Aggregate} --command --no-interaction` — id ของรายการที่แก้เป็น field ของ Command (`id` หรือ `{aggregate}Id`) ไม่ส่งแยก (`handlers.md`)
2. เขียน **Command** ให้ครบก่อน — ชนิดของแต่ละพารามิเตอร์คือสิ่งที่ generator ขั้นถัดไปใช้เดา rule: string / `?string` / int / bool / enum / `array` / `UploadedFile` เวลาเป็น `HH:mm` string ให้ handler แปลงเป็น VO; เงิน/อัตรา/ตัวคูณเป็น decimal string (`'1500.50'`) หรือ VO — **ห้าม `float`** (numbers.md, เทสต์ `no-float`)
3. เขียน **Handler**: `{Aggregate}Entity::create(...)` → `$this->repo->save()` (create) หรือ `getById()` → method ของ entity → `update()` (update) ทุก handler ที่เขียนเปิด `DB::transaction` และบันทึก audit entry ในนั้น (`audit-log.md`) ใส่ `@throws` ทุก domain exception ที่ทะลุได้
4. `php artisan make:form-request {Aggregate} --domain={Context} --no-interaction` ได้ `Validates{Aggregate}` + `Store`/`Update{Aggregate}Request` + `{Aggregate}FormValues` แล้วเขียนต่อ:
   - rules ที่ generator เดาเป็นแค่จุดเริ่ม: เติม unique (`abstract protected function {x}UniqueRule(): Unique` ใน trait ให้แต่ละ request เขียนเอง), ขอบเขตจาก enum, `exclude`, `after()` reuse VO, ไฟล์ด้วย `File::image()/types()` + ขนาดจาก `config()`
   - `rules()` ต้อง `return [...]` เป็น array literal (เทสต์อ่านคีย์จากตรงนี้)
   - `toCommand()` ใช้ `new {Name}Command(...)` + named args เท่านั้น ห้าม `::from([...])`
   - helper แคบ type (**ห้ามชื่อ `image()`** — `Request::image()` มีอยู่แล้ว)
   - `{Aggregate}FormValues`: คีย์ของ `toArray()` = คีย์ระดับบนของ `Store{Aggregate}Request::rules()` (เทสต์ `form-values` ตรวจ) เขียน `empty()` (ค่าตั้งต้นตอนสร้าง) และ `of($model)` (แปลงค่าที่เก็บเป็นค่าที่ input แสดง) ให้ครบ — `of()` โยน `LogicException` จนกว่าจะเขียน
5. **Controller**: `php artisan make:controller {Aggregate} --domain={Context} --only=create,store,edit,update --no-interaction` (เพิ่ม `--update=`/`--create=` เมื่อ use case ไม่ได้ชื่อ `Update{Aggregate}`/`Create{Aggregate}`) — **ห้ามเขียน method เอง** generator เติม 4 method นี้ลง controller เดิม (หรือสร้างใหม่) ตามลำดับ resource ไม่แตะ method เดิม และเขียน `CreateTest`/`StoreTest`/`EditTest`/`UpdateTest` ที่มี `->todo()` จากนั้นเติมสิ่งที่มันไม่รู้ตามคำเตือนที่มันพิมพ์ (บรรทัด `can` ของ index ที่ขาด, ability, คีย์คำแปล):
   - `index`: `'create' => Gate::allows('create', Model::class)` ใน `can`
   - `create()`: `Gate::authorize('create', Model::class)` → `Inertia::render('{aggregates}/create', ['defaults' => {X}FormValues::empty()])`
   - `store(Store{X}Request $request, Create{X}Handler $handler)`: ไม่ `Gate::authorize` ซ้ำ → `$id = $handler($request->toCommand())` → `Inertia::flash(FlashToast::KEY, FlashToast::success(...))` เป็น statement → `return to_route('{aggregates}.show', $id)`
   - `edit($model)`: `Gate::authorize('update', $model)` → render ด้วย `'{model}' => $model` (breadcrumb + `update.form()`) และ `'defaults' => {X}FormValues::of($model)`
   - `update(Update{X}Request $request, $model, Update{X}Handler $handler)`: `$handler($request->toCommand())` → flash → `to_route('{aggregates}.show', $model)`
   - ห้ามสร้าง Command ใน controller และห้ามเปิด transaction ที่นี่
6. ทุก `lang/*.json` (เรียงคีย์): `{aggregates}.create` (ปุ่ม), `form_title`, `create_title`, `create_description`, `edit_title`, `edit_description`, `created`/`updated` (`:name`), label ของทุกฟิลด์, `validation.{rule}` สำหรับ error จาก `after()`
7. `php artisan wayfinder:generate --with-form`

### 2. Frontend

ลำดับ:

1. `php artisan make:form-page {Aggregate} --no-interaction` อ่าน `@return array{…}` ของ `{X}FormValues::toArray()` แล้วเขียน type `{X}FormValues` ลง `types/{aggregate}.ts`, `components/{aggregate}/form.tsx` (หนึ่ง input ต่อคีย์) และ shell ของ `create.tsx`/`edit.tsx` และ Browser test `tests/Browser/{Aggregates}/CreateTest.php` + `EditTest.php` พร้อม `->todo()` — รายงาน route กับคำแปลที่ยังขาด
2. แต่ง type ใน `types/` ให้แคบลงได้ (enum เป็น string union) แต่คีย์ต้องตรงกับ PHP
3. แต่ง form component:
   - คง `<Form {...action}>` แบบ uncontrolled (`defaultValue={defaults.x}`) `useState` เฉพาะฟิลด์แม่ที่คุมการโชว์, ค่าของ ToggleGroup/Checkbox ที่ต้องยิงผ่าน hidden input, ค่าที่ widget เลือกแล้วเขียนลง hidden input
   - enum: `<Select name defaultValue>` จากค่าคงที่ `X_OPTIONS` พร้อม `@see` และ `t('{aggregates}.{field}.${value}')`
   - หลายค่า: `<ToggleGroup type="multiple">` + `<input type="hidden" name="x[]">` ต่อค่า, error อ่าน `errors.x ?? errors['x.0']`
   - เวลา: `<Input type="time">` ส่ง `HH:mm` ตรง ๆ
   - เงิน/อัตรา: `<Input inputMode="decimal">` รับ decimal string ตรง ๆ ยอดที่คำนวณโชว์ก่อนกดส่งใช้ `addMoney`/`subtractMoney` จาก `@/lib/numbers`
   - ฟิลด์ที่แก้ไม่ได้: prop `mode: 'create' | 'edit'`
   - ห้าม `useForm` / `useHttp` / `router` / `fetch` (ESLint `form-only`)
4. หน้า create/edit เป็น shell: รับ `defaults` จาก props ส่งต่อให้ฟอร์ม ห้ามมี `<Form>`/input หรือสร้างค่าตั้งต้นเอง (ESLint `page-shell`, เทสต์ `pages`) หน้า edit ปรับ type ของ model prop ให้เป็น `{X}Detail` ถ้ามี
5. `pages/{aggregates}/index.tsx`: `can.create` ใน Props → `<DataTable headerActions={can.create ? <Button asChild><Link href={create()}><Plus />{t('{aggregates}.create')}</Link></Button> : undefined} />` — **ไม่ใช่ `toolbar`**

### 3. Tests

generator scaffold ไฟล์เทสต์ที่มี `->todo()` ไว้แล้ว — เขียนในไฟล์เดิม ห้ามสร้างซ้ำ (path ตาม `testing.md`)

| ไฟล์ | ต้องมีเคส |
| --- | --- |
| `Create{X}HandlerTest` / `Update{X}HandlerTest` | persist ทุก field + audit entry; ฟิลด์ตามเงื่อนไขเป็น null; สองครั้งได้ id ต่างกัน (create); ทุก domain exception ที่ทะลุได้ (dataset); unique ซ้ำ → `expectFailedWrite(...)`; repo throw → ทะลุไม่ถูกกลืน |
| `{Aggregate}Controller/CreateTest` | guest → login; role ที่เขียนได้ → `component('{aggregates}/create')` + `where('defaults', [...])` ทุกคีย์; role ที่ไม่มีสิทธิ์ → `assertForbidden` |
| `{Aggregate}Controller/EditTest` | เหมือน Create + `defaults.*` เป็นค่าที่แปลงแล้วของแถวนั้น; id ที่ไม่มี → 404 |
| `{Aggregate}Controller/StoreTest` / `UpdateTest` | helper `valid{X}Payload($overrides)`; สำเร็จ → `assertRedirect(show)` + `assertInertiaFlash('toast.*')` + ทุกคอลัมน์; dataset validation `[overrides, field]` → `assertInvalid([$field])` + count คงเดิม; เคสขอบที่ **ผ่าน**; role ที่ไม่มีสิทธิ์สองทาง (403 / `X-Inertia` → toast error); ฟิลด์ที่ลักลอบส่งมาถูกทิ้ง (update) |
| `{Aggregate}Controller/IndexTest` | `can.create` ต่อ role |
| `tests/Browser/{Aggregates}/CreateTest` / `EditTest` (generator เขียนไว้แล้ว) | render ไม่มี JS error (edit: เห็นค่าที่เก็บไว้); กรอกแล้วบันทึก → ไปหน้าปลายทาง + เห็น toast; ส่งค่าผิด → error ใต้ช่องที่ถูกต้อง; ฟิลด์ตามเงื่อนไขซ่อน/โชว์ |

helper ต่อไฟล์ตั้งชื่อไม่ซ้ำข้ามไฟล์ (`{aggregates}CreateActor`, `{aggregates}StoreActor`) — Pest แชร์ global namespace

### 4. ตรวจ

```bash
vendor/bin/pint --dirty --format agent
vendor/bin/phpstan analyse
php artisan test --compact --testsuite=Architecture
php artisan test --compact --filter={Aggregate}
php artisan wayfinder:generate --with-form && npx tsc --noEmit
npx eslint <ไฟล์ที่แตะ>
npx prettier --check <ไฟล์ที่แตะ> lang/*.json
npm run build && php artisan test --compact --testsuite=Browser
```

แล้วตรวจกับ dev server จริง (`composer run dev`): ปุ่มสร้างโชว์เฉพาะ role ที่สร้างได้ · หน้า create/edit เรนเดอร์พร้อมค่าตั้งต้น · ฟิลด์ตามเงื่อนไขซ่อน/โชว์ · บันทึก → ไปหน้า show + toast · ส่งค่าผิดได้ error ที่ช่องที่ถูกต้อง · **ลบแถวทดสอบทิ้ง**หลังเสร็จ

---

## สิ่งที่ห้าม

- validate ใน controller หรือบน spatie Data — FormRequest เท่านั้น
- สร้าง Command ใน controller หรือใช้ `Command::from([...])` — `$request->toCommand()` ที่ใช้ `new` + named args
- `Update{X}Request extends Store{X}Request` — `toCommand()` คืน Command คนละคลาส ให้ใช้ trait `Validates{X}` ร่วมกัน
- `Gate::authorize` ซ้ำใน `store()`/`update()` — สิทธิ์อยู่ใน `authorize()` ของ request
- สร้างค่าตั้งต้นของฟอร์มฝั่ง TS (`EMPTY_VALUES`, `toFormValues()`) — มาจาก `{X}FormValues` เป็น prop `defaults`
- เขียนกฎข้ามฟิลด์ใหม่ทั้งที่ domain มี VO อยู่แล้ว / เขียนเลขขอบเขตซ้ำทั้งที่ enum มีให้
- `required_unless` สำหรับฟิลด์ที่ไม่ใช้ในเงื่อนไข — ใช้ `exclude`
- เก็บ/ย้ายไฟล์ หรือเรียก `Storage::` ใน `app/Http` — handler ทำผ่าน storage port
- `Inertia::flash(...)->route(...)` หรือ `->with(...)` — flash เป็น statement แล้ว `to_route()`
- `useForm`/controlled ทุกฟิลด์ — `<Form>` uncontrolled แล้ว `useState` เฉพาะที่จำเป็น
- setState ใน `useEffect` (eslint `react-hooks/set-state-in-effect`)
- ปุ่มสร้างใน `toolbar` ของ DataTable — ใช้ `headerActions`

## เช็คลิสต์ปิดงาน

**สเปก**
- [ ] ตารางสรุปสเปกได้รับการยืนยันจากผู้ใช้ก่อนแตะไฟล์
- [ ] ทุกจุดที่ตัดสินใจเองถูกสรุปให้ผู้ใช้เห็น

**Backend**
- [ ] scaffold ด้วย `make:use-case` + `make:form-request`; handler create คืน id
- [ ] FormRequest: `authorize()`, rules เป็น literal, unique ตาม key, `exclude`, `after()` reuse VO, `attributes()`, `toCommand()` ด้วย `new`
- [ ] `{X}FormValues` คีย์ตรง rules; `empty()`/`of()` เขียนครบ
- [ ] Controller scaffold ด้วย `make:controller --only=create,store,edit,update` ไม่เขียนเอง แล้วเติมตามคำเตือนของ generator; `can.create` ใน index
- [ ] คำแปลครบทุก `lang/*.json`; wayfinder regenerate

**Frontend**
- [ ] scaffold ด้วย `make:form-page`; type ใน `types/` มี `@see`
- [ ] form component เดียวใช้ทั้งสองหน้า uncontrolled + state เฉพาะที่จำเป็น
- [ ] create/edit เป็น shell รับ `defaults`; ปุ่มสร้างใน `headerActions`

**Tests + ตรวจ**
- [ ] Handler / Create / Edit / Store / Update / Index / Browser เขียวทั้งหมด (รวมเคสขอบที่ **ผ่าน**, ไม่เหลือ `->todo()` ใน Browser test)
- [ ] Architecture (`FormPagesTest`) / pint / phpstan / tsc / eslint / prettier / Browser ผ่าน
- [ ] ตรวจกับ dev server ตามข้อ 4 แล้วรายงานผลตามจริง และลบข้อมูลทดสอบทิ้ง
