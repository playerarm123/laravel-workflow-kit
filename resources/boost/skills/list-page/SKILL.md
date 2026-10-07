---
name: list-page
description: "Build a server-paginated list page end to end (List{Aggregate}s use case, Eloquent read query, resource controller index/destroy, Inertia React page on useDataTable + DataTable + useActions, Pest tests) with the workflow kit's generators. Activate when the user asks to create a list page, index page, table page, data table, or CRUD listing for an aggregate; says ทำหน้า list, หน้ารายการ, ตาราง {aggregate}, หน้า index; or invokes /list-page. Starts by collecting the full page spec from the user through explicit questions — never guesses columns, filters, sorting, or row actions — then implements backend, frontend, and tests in order. Do not use for create/edit forms, detail (show) pages, or changes to the shared DataTable component itself."
---

# List Page

หน้า list = ตารางที่ค้นหา กรอง เรียง แบ่งหน้า จากเซิร์ฟเวอร์ + ปุ่มของแถว (view / edit / delete / อื่น ๆ) + destroy ที่ผูกกับตาราง
skill นี้พาทำทั้งสาย backend → frontend → tests ด้วย generator ของ kit แล้วเติมส่วนที่ generator เขียนให้ไม่ได้

**ขอบเขต:** หน้า `index` และ `destroy` เท่านั้น — ไม่รวม create/edit form (ใช้ `/create-page`), หน้า show, และไม่แก้ `DataTable`/`useDataTable`/`useActions` กลาง (ถ้าหน้าใหม่ต้องการความสามารถที่ตารางกลางยังไม่มี ให้หยุดรายงานผู้ใช้ก่อน)

กฎที่ต้องอ่านก่อนแตะไฟล์: guideline `list-queries.md` (backend), `list-pages.md` (frontend), `handlers.md`, `authorization.md`, `dates.md`, `numbers.md`, `testing.md` และ `.ai/rules` ของโปรเจกต์ถ้ามี (อ่าน `.ai/rules/index.md` หาไฟล์ที่ตรงกับ path)

ถ้าโปรเจกต์มีหน้า list ที่ทำเสร็จแล้ว ให้เปิดดูหนึ่งหน้าเป็นแบบ (หา `useDataTable(` ใน `resources/js/pages/**/index.tsx`) — แต่สิ่งที่ generator เขียนคือความจริง ถ้าขัดกันให้เชื่อ generator และ guideline

---

## ขั้น 0 — เก็บสเปกหน้าให้ครบก่อนแตะไฟล์ (เกตบังคับ)

**กฎ: ห้ามเดา** ทุกข้อด้านล่างต้องมีคำตอบจากผู้ใช้ หรืออ่านได้ชัดจากโค้ดที่มีอยู่ (Model, migration, Enum, Policy) ก่อนสร้างไฟล์แรก

วิธีถาม:
- ใช้ `AskUserQuestion` ทีละชุด (สูงสุด 4 คำถามต่อครั้ง) เรียงชุด A → D
- ก่อนถามแต่ละชุด **อ่านโค้ดก่อน** แล้วเอาสิ่งที่เจอมาเป็นตัวเลือกให้เลือก — เช่น ลิสต์คอลัมน์จาก migration/`#[Fillable]`, enum ที่มีใน `app/Domain/{Context}/**/Enums`, role/enum ที่ policy อ่าน, method ที่มีใน Policy — ไม่ถามคำถามเปิดถ้าให้ตัวเลือกได้
- ถ้าผู้ใช้ตอบคลุมเครือ ("เอาแบบหน้าเดิม" / "ตามที่คิดว่าเหมาะ") ให้ **ถามซ้ำให้แคบลง** โดยเสนอค่าที่จะใช้จริงให้ยืนยันทีละข้อ ห้ามตีความเอง
- ไม่ลงมือจนกว่าจะปิดครบทุกชุด แล้ว **สรุปสเปกเป็นตารางเดียวให้ผู้ใช้ยืนยันหนึ่งครั้ง** ก่อนเริ่มขั้น 1

### ชุด A — ตัวตน

- aggregate / Model ชื่ออะไร อยู่ context ไหน → กำหนด namespace `App\Application\{Context}`, route `{aggregates}`, lang prefix `{aggregates}.`, path หน้า `pages/{aggregates}/index.tsx`
- ใครเห็นหน้านี้ได้ (→ `viewAny`) และ Policy มีอยู่แล้วหรือต้องสร้าง (`make:policy`)
- รูปแบบหน้า: **Table** (คอลัมน์ เรียง แบ่งหน้า — ค่าเริ่มต้น) หรือ **Grid** (การ์ดที่เลื่อนแล้วโหลดต่อด้วย `Inertia::scroll()` — ไม่มีให้เลือกเรียงหรือจำนวนแถว) → Grid ใช้ `--grid` ทั้ง `make:controller` และ `make:list-page`

### ชุด B — ตาราง (Grid: ถามว่าการ์ดโชว์ field ไหน เรนเดอร์แบบไหน แทนคอลัมน์ — ไม่ถามเรื่องคอลัมน์ที่เรียงได้ ใช้แค่ default sort)

- คอลัมน์ที่โชว์ **ทีละคอลัมน์**: มาจาก field ไหน, ชนิด (string / int / enum / datetime / เงิน / relation), เรนเดอร์แบบไหน (ข้อความ, badge, วันที่ตาม locale, เงิน, icon)
- คอลัมน์ไหน **เรียงได้** → `{Aggregate}ListSort` enum และ **default sort column + direction**
- คอลัมน์ไหนเป็น enum → ต้องมีคำแปลต่อค่า `{aggregates}.{field}.{value}` ในทุก `lang/*.json`

### ชุด C — ค้นหา / กรอง

- free-text search วิ่งบนคอลัมน์ไหน → `SEARCHABLE_COLUMNS` (หรือไม่มี search)
- ช่วงวันที่สร้าง (`created_from`/`created_to` → `DateRange $createdAt` + `applyCreatedBetween()`) มีทุกหน้าโดยไม่ต้องถาม — ถามเฉพาะเมื่อคอลัมน์ไม่ใช่ `created_at`
- ตัวกรองแต่ละตัว: ชื่อ query param, เป็น enum ไหน (→ `tryFrom` fallback `null`) หรือค่าแบบอื่น, ตัวเลือกใน toolbar เป็น Select หรืออย่างอื่น
- `PageSize` เป็น 10/25/50 คงที่ ถ้าต้องการชุดอื่นต้องบอก (และแก้ kit file = เกินขอบเขต skill นี้)

### ชุด D — ปุ่มของแถว / bulk

- **view**: เปิดหน้า show (`actions.view.page({ route: show })`) หรือ dialog (`actions.view.dialog({ dialog })` — หน้าเพจเรนเดอร์ dialog เอง body มีอะไรบ้าง)
- **edit**: มีไหม, เป็น page / dialog / ยัง disabled รอ backend
- **delete**: มีไหม, ใครลบได้ (→ `can.delete` จาก `deleteAny`), ข้อความยืนยันต้องมีชื่อ item ไหม, ข้อความ toast หลังลบ
- action อื่นของแถวและ bulk action → ทำด้วย `make:action` ตาม guideline `actions.md` (มี endpoint แล้วหรือแค่ placeholder `disabled`)
- relation ที่คอลัมน์ต้องใช้ → eager load ใน adapter

### สิ่งที่ตัดสินใจเองได้ ไม่ต้องถาม (แต่ต้องอยู่ในตารางสรุป)

ชื่อไฟล์/คลาสตาม convention, ลำดับคอลัมน์ตามที่ผู้ใช้ไล่มา, icon ของปุ่มมาตรฐาน (preset ใน `use-actions.ts`), คีย์คำแปลตาม prefix, ชื่อ test case, ชื่อ helper ในเทสต์

### ตารางสรุปสเปก (ให้ผู้ใช้ยืนยันก่อนขั้น 1)

| หัวข้อ | ค่า |
| --- | --- |
| Aggregate / Context / route / lang prefix | |
| รูปแบบหน้า (Table / Grid) | |
| Policy / ใครเห็น / ใครลบ | |
| คอลัมน์ (field → เรนเดอร์) | |
| เรียงได้ + default | |
| search columns | |
| filters (param → enum/ค่า) | |
| row actions (view/edit/delete/อื่น) | |
| bulk actions | |
| eager loads | |

---

## Workflow

ถ้าโปรเจกต์ใช้ structure manifest (`.kit/structure/`) ให้ออกแบบ use case และ page ลง manifest ก่อน แล้วให้ `php artisan kit:apply` รันคำสั่งในข้อ 1 และข้อ 7 ให้ (guideline `structure.md`) — ขั้นที่ apply หยุดรอคือส่วนที่คนต้องเติม

### 1. Backend

1. `php artisan make:use-case List{Aggregate}s --domain={Context} --command --result --query --no-interaction` — **ห้ามใส่ `--repo`** (repository เป็นฝั่งเขียน แบ่งหน้าไม่ได้) ได้ Command, Criteria, Handler, Query (port), Result, `{Aggregate}ListRow`, `{Aggregate}ListSort` (ระดับ context), adapter `EloquentList{Aggregate}sQuery` และเทสต์ที่มี `->todo()`
2. `{Aggregate}ListSort`: เติม case ทุกคอลัมน์ที่เรียงได้ `fromInput()` fallback ไป default ไม่ throw
3. `{Aggregate}ListRow implements Arrayable` — **ไม่ใช่ spatie `Data`** (จะเปลี่ยน envelope ของ paginator) `toArray()` คือสัญญาที่ TS mirror เขียน `@return array{…}` ให้ครบทุกคีย์ เพราะ `make:list-page` อ่านจากตรงนี้ เงินส่งเป็น decimal string ตาม `numbers.md` วันเวลาเป็น ISO 8601 ตาม `dates.md`
4. `Criteria` ไม่มี default ทุก field required `toFilters()` มี `@return array{…}` ครบ; `Handler` เป็นที่เดียวที่ settle ค่าดิบ (`fromInput`, `tryFrom`, `trim`, `PageSize::fromInput`, `DateRange::fromInput`) แล้ว Result echo `toSort()`/`toFilters()` กลับ
5. Adapter `extends EloquentListQuery`, `SEARCHABLE_COLUMNS`, `applySearch()`, จบด้วย `paginateRows()` (ไม่ใช้ `->paginate(` ตรง ๆ), eager load ตามสเปก, `sortColumn()`/`toRow()` private — กฎเต็มใน `list-queries.md`
6. Binding ของ port → adapter ใน `$bindings` ของ service provider (generator พิมพ์บรรทัดให้)
7. Controller: `php artisan make:controller {Aggregate} --domain={Context} --only=index,destroy --no-interaction` — **ห้ามเขียน controller เอง** generator อ่าน `List{Aggregate}s{Command,Result}` แล้วเขียน `index` (`Gate::authorize('viewAny')` → `$request->string('x')->toString()` ดิบทุกตัวเข้า Command, **ไม่มี FormRequest** → render `{aggregates}`/`sort`/`filters`/`can`) กับ `destroy` (`Gate::authorize('delete')` → `Delete{Aggregate}Handler($model->id)` → toast → `back()`) และเทสต์ `{Aggregate}Controller/IndexTest.php`, `DestroyTest.php` ที่มี `->todo()` — Grid เติม `--grid` แล้ว index จะส่ง `Inertia::scroll($result->…)` และไม่ส่ง `sort` (ถ้ามันเตือนว่า Command ยังรับ `sort`/`direction` ให้ลบออกจาก Command)
   ถ้า controller มีอยู่แล้ว generator เติมเฉพาะ method ที่ขาด ไม่แตะของเดิม ที่ต้องเติมเอง: `:name` ใน toast `{aggregates}.deleted` (เก็บชื่อไว้ก่อนลบ), `can` ของโมเดลอื่น, และ `catch` refusal ของ entity ใน `destroy` ตามชื่อ (`exceptions.md`) — อ่านคำเตือนที่ generator พิมพ์ (ability ที่ policy ยังไม่มี, คีย์คำแปลที่ขาด, บรรทัด route)
8. Policy `viewAny` / `view` / `deleteAny` / `delete` ถ้ายังไม่มี (`make:policy`) + route `Route::resource(…)->only(['index', 'destroy'])`

### 2. Frontend

1. `php artisan make:list-page List{Aggregate}s --domain={Context} --no-interaction` — หลัง backend เสร็จแล้วเท่านั้น เพราะอ่าน `@return array{…}` ของ `toArray()`/`toFilters()` ถ้า docblock ไม่ครบจะ fail:
   - `resources/js/types/{aggregate}.ts`: `{Aggregate}Row`, `{Aggregate}Filters`, `{Aggregates}Query` พร้อม `@see` (ต่อท้ายไฟล์เดิมเฉพาะ type ที่ยังไม่มี) และ export ใน `types/index.ts`
   - toolbar `components/{aggregate}/table-toolbar.tsx`: Select จาก case ของ enum filter และ date range จากคู่ `{x}_from`/`{x}_to`
   - หน้า `pages/{aggregates}/index.tsx`: หนึ่งคอลัมน์ต่อคีย์ของ Row (ยกเว้น `id`), `visit` ตัวเดียว, `perPageOptions`, `emptyState` + `noResultsState`
   - Browser test `tests/Browser/{Aggregates}/IndexTest.php` (mirror ของหน้า ตาม `testing.md`) พร้อม `->todo()`
   - **Grid (`--grid`)**: ไม่มี `{Aggregates}Query` (toolbar รับ `dt: ListQuery<{Aggregate}Filters>`), หน้าใช้ `useListQuery` + `<InfiniteScroll>` + toolbar + `{Aggregate}Card`, และเขียน `components/{aggregate}/card.tsx` แสดงหนึ่ง label/ค่าต่อคีย์ของ Row (ยกเว้น `id`) — เติมการจัดรูปค่าและปุ่มของรายการในการ์ด ไม่ใช่ในหน้า
   - ไฟล์หน้า, toolbar, การ์ด หรือ Browser test ที่มีอยู่แล้วจะไม่ถูกเขียนทับ ส่วน warning ตอนจบบอก route และคีย์คำแปลที่ยังขาด
2. แก้ type ที่ generator เขียนเป็น `string` หรือ `unknown` ให้แคบลงตามสเปก (enum เป็น string union พร้อม `@see` ไฟล์ PHP) แล้ว `php artisan wayfinder:generate --with-form` และเช็คว่า `@/routes/{aggregates}` มี `index`/`show`/`edit`/`destroy` ตามที่หน้าใช้
3. เติมหน้าเพจตามสเปก — ลำดับใน component:
   `useTranslation` → `useActions<Row>()` → `useConfirmDialog`/`useItemDialog` ตามสเปก → `setLayoutProps({ breadcrumbs })` → คอลัมน์ (ลบที่ไม่โชว์, เรียงตามสเปก, เติม `cell`/`enableSorting: false` — วันที่จัดรูปผ่าน `@/lib/dates` เท่านั้น: `formatDateTime`/`formatDate` ส่ง `locale`/`timezone` จาก `useTranslation()`, วัน `Y-m-d` ใช้ `formatCalendarDate`; เงิน/อัตราจัดรูปผ่าน `@/lib/numbers` เท่านั้น: `formatMoney` ส่ง `locale`/`currency`, อัตราใช้ `formatPercent`) → `rowAction` + `can` ใน props → `<DataTable dt toolbar bulkActions headerActions />` + dialog ที่หน้าเพจเรนเดอร์เอง — **ห้ามแตะ `query`/`visit` ที่ generator เขียน ห้ามพา `page`**
4. toolbar: เติม `options` ของตัวกรองที่ไม่ใช่ enum (generator เตือนชื่อไว้แล้ว) และใส่ `search={false}` ถ้าไม่มีคอลัมน์ให้ค้น — **toolbar ห้ามมี `router.get`/debounce/dialog/state ของตัวเอง**
5. คำแปลในทุก `lang/*.json` (เรียงคีย์ตามตัวอักษร): `{aggregates}.title` `description` `confirm_delete` (`:name`) `deleted` (`:name`) `empty_title` `empty_description` `no_results_title` `no_results_description` + หัวคอลัมน์ทุกอัน + ค่า enum ทุกค่า

### 3. Tests

generator scaffold ไฟล์เทสต์ที่มี `->todo()` ไว้แล้ว — เขียนในไฟล์เดิม ห้ามสร้างซ้ำ (path ตาม `testing.md`)

| ไฟล์ | ต้องมีเคส |
| --- | --- |
| `List{Aggregate}sHandlerTest` | default sort + ไม่มี filter, fallback เงียบเมื่อค่าไม่รู้จัก (sort/direction/filter/perPage), เรียงได้ทุกค่าใน enum (dataset), desc, search (case-insensitive, escape `%`/`_`, trim + echo), แต่ละ filter + echo, paging default 10 / ทุก size / fallback, row มีทุก field, envelope แบน |
| `EloquentList{Aggregate}sQueryTest` | เรียก `listQueryContract()` + resolve จาก port, เรียงตามคอลัมน์จริงทุก sort key (dataset), paging links ชี้ request ปัจจุบัน |
| `{Aggregate}Controller/IndexTest` | guest redirect login; `assertInertia` `component('{aggregates}/index')` + `has('{aggregates}')` `has('sort')` `has('filters')` + `where('can.x', bool)` dataset ตามผู้ใช้ที่ policy ตอบต่างกัน |
| `tests/Browser/{Aggregates}/IndexTest` | render ไม่มี JS error/console log; สิ่งที่เบราว์เซอร์คำนวณเอง (format เงิน/เรท/วันที่, badge); toolbar ค้น/กรองแล้ว URL ยังพา `sort`/`direction`; empty + no-results state; dialog ของปุ่มแถว |
| `{Aggregate}Controller/DestroyTest` | guest redirect + `assertModelExists`; ผู้ที่ลบได้: `->from(index)->delete()->assertRedirect(index)` + `assertInertiaFlash('toast.type', 'success')` / `toast.title` / `toast.message` + `assertModelMissing`; ผู้ที่ลบไม่ได้: `assertForbidden` + `assertModelExists` |

helper ต่อไฟล์ตั้งชื่อไม่ซ้ำข้ามไฟล์ (`{aggregates}IndexActor`, `{aggregates}DestroyActor`) — Pest แชร์ global namespace

### 4. ตรวจ

```bash
vendor/bin/pint --dirty --format agent
vendor/bin/phpstan
php artisan test --compact --filter={Aggregate}
php artisan test --compact --testsuite=Architecture
npx tsc --noEmit
npm run build && php artisan test --compact tests/Browser/{Aggregates}
npx eslint <ไฟล์ที่แตะ>
npx prettier --check <ไฟล์ที่แตะ>
```

แล้วเปิด browser (ต้องมี `composer run dev` รันอยู่ — ถ้าไม่เห็นการเปลี่ยนแปลงให้ถามผู้ใช้): เรียงทุกคอลัมน์ที่เรียงได้ · แต่ละ filter + search พา `sort`/`direction`/`per_page` เดิมไปด้วย · เปลี่ยนจำนวนแถว · เลือกแถวแล้วเปลี่ยนหน้า → แถบ bulk หาย · ปุ่มแถวทุกตัว (tooltip ขึ้น) · ลบ → dialog มีชื่อ → toast · empty state สลับตามมี/ไม่มีตัวกรอง · URL ที่แก้มือ (`?sort=xxx&status=zzz`) ยังได้หน้า ไม่ใช่ error

---

## สิ่งที่ห้าม

- ก๊อป `<Table>` markup หรือเรียก `useTable` เองในหน้าเพจ — ใช้ `useDataTable` + `<DataTable>` เท่านั้น
- validate ค่าของ list ใน controller หรือ FormRequest — handler เป็นคน settle + fallback (URL ที่แก้มือต้องได้หน้า ไม่ใช่ 422)
- ให้ `DataTable` เรนเดอร์ dialog — dialog ทุกตัวหน้าเพจวางเองข้างตาราง
- ใส่ `--repo` ให้ list use case / คืน domain entity จาก read port
- generalise ข้าม list (Criteria กลาง, `BackedEnum` sort, `array $filters`) — แต่ละ list มี type ของตัวเอง (`list-queries.md`)
- ใช้ `Data` ของ spatie เป็น row

## เช็คลิสต์ปิดงาน

**สเปก**
- [ ] ตารางสรุปสเปกได้รับการยืนยันจากผู้ใช้ก่อนแตะไฟล์
- [ ] ทุกจุดที่ตัดสินใจเองถูกสรุปให้ผู้ใช้เห็น

**Backend**
- [ ] scaffold ด้วย `make:use-case --query` ไม่ใส่ `--repo`
- [ ] Sort enum + `fromInput()` fallback
- [ ] Row เป็น `Arrayable` ไม่ใช่ `Data`; `toArray()` ครบทุกคอลัมน์ในสเปก พร้อม `@return array{…}`
- [ ] Criteria ไม่มี default, `toSort()`/`toFilters()`, `@param 'asc'|'desc'`
- [ ] Handler settle ทุกค่าดิบ; Result echo sort/filters
- [ ] Adapter `extends EloquentListQuery`, จบด้วย `paginateRows()`, eager load ครบ, เทสต์เรียก `listQueryContract()`
- [ ] binding ของ port ใน provider
- [ ] Controller index/destroy scaffold ด้วย `make:controller --only=index,destroy` ไม่เขียนเอง; Policy + route

**Frontend**
- [ ] scaffold ด้วย `make:list-page` ไม่เขียนเองจากศูนย์
- [ ] row type ตรง `toArray()` ทีละคีย์ + `@see`
- [ ] columns ใน component ด้วย `DataTableFeatures`
- [ ] `useDataTable` + `<DataTable dt>`; ปุ่มแถวจาก `useActions`; dialog หน้าเพจเรนเดอร์เอง
- [ ] `query` มีทุกคีย์ของ Filters + `sort`/`direction`/`per_page`; `visit` ตัวเดียว ไม่พา `page`; toolbar รับ `dt` แล้วห่อ `<DataTableToolbar dt fields>`
- [ ] คำแปลครบทุก `lang/*.json`

**Tests + ตรวจ**
- [ ] 5 ไฟล์เทสต์ตามตารางข้างบนเขียวทั้งหมด (ไม่เหลือ `->todo()`)
- [ ] pint / phpstan / tsc / eslint / prettier ผ่าน
- [ ] เช็ค browser ตามรายการข้อ 4 แล้วรายงานผลตามจริง
