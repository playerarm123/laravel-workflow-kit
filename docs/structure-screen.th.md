# หน้าจอ structure

[English](structure-screen.md)

`/kit/structure` คือหน้าจอสำหรับออกแบบโครงสร้างของโปรเจกต์ก่อนลงมือสร้างโค้ด หน้าจอวาด manifest ใน `.kit/structure/` ออกมาเป็นไดอะแกรม ให้แก้ manifest ผ่านฟอร์มข้างไดอะแกรม และบอกในการ์ดทุกใบว่าโค้ดสร้างไปถึงไหนแล้ว หน้าจอนี้ทำหน้าที่ออกแบบอย่างเดียว คือเขียนไฟล์ JSON ไม่เคยรันคำสั่งใด ๆ ส่วนการสร้างโค้ดตามแบบเป็นหน้าที่ของ `php artisan kit:apply`

คู่มือนี้พาทำตัวอย่างหนึ่งชิ้น ตั้งแต่ context ว่าง ๆ จนได้โค้ดที่ใช้งานได้ แล้วตามด้วยคำอธิบายทุกส่วนของหน้าจอ กฎที่หน้าจอบังคับอยู่ใน [structure.md](../resources/boost/guidelines/structure.md) คู่มือนี้จะลิงก์ไปที่กฎแทนการเขียนซ้ำ

## ก่อนเริ่ม

หน้าจอนี้เปิดได้เฉพาะเมื่อแอปรันด้วย `APP_ENV=local` เพราะไม่มีการ login และไม่ผ่าน policy ใด ๆ จึงไม่มีทางหลุดไปถึงแอปที่ deploy แล้ว สิ่งที่ต้องมี:

- `App\Providers\KitServiceProvider` อยู่ใน `bootstrap/providers.php`
- `resources/js/kit/structure.tsx` อยู่ใน `input` ของ `laravel()` ใน `vite.config.ts`
- Vite: รัน `npm run dev` ระหว่างทำงาน หรือ `npm run build`

`php artisan kit:setup` ตั้งสองข้อแรกให้แล้ว จากนั้นเปิด `http://your-app.test/kit/structure` ได้เลย ด้านบนมีลิงก์ **Guide** ที่เปิดคู่มือนี้ และ **Docs** ที่พาไป `/kit/docs` ซึ่งรวมกฎและคู่มือของ kit

โปรเจกต์ใหม่จะเห็นการ์ดใบเดียวในหน้าภาพรวม คือ `Shared` ซึ่งเป็น shared kernel ที่ทุก context ใช้ได้

![หน้าภาพรวมของโปรเจกต์ใหม่](images/structure-overview-empty.png)

## ตัวอย่างทำตาม: จาก context ว่างจนได้โค้ด

ตัวอย่างนี้สร้าง context ชื่อ `Shipping` ที่มี aggregate หนึ่งตัวชื่อ `Crate` และหน้าแสดงรายการ crate

### 1. สร้าง context

กด **New context** พิมพ์ `Shipping` แล้วกด **Create** ชื่อ context ต้องเป็น StudlyCase และห้ามซ้ำกับโฟลเดอร์ของ kit เอง (`Shared`, `Audit`, `Auth`, `Concerns`) เสร็จแล้วหน้าจอจะเปิด context ใหม่ที่ยังว่างอยู่

![ฟอร์ม New context](images/structure-new-context.png)

![context ที่ยังว่าง](images/structure-context-empty.png)

### 2. เพิ่ม aggregate

กด **Add aggregate** แล้วกรอก:

- **Name:** `Crate`
- **Child entities, separated by commas:** `Lid`
- **Repository:** ติ๊กไว้ เพื่อให้ aggregate นี้บันทึกผ่าน `CrateRepository`

กด **Add**

![การเพิ่ม aggregate](images/structure-add-aggregate.png)

การ์ดใหม่จะขึ้นสถานะ **ready** คลิกการ์ดแล้ว side panel จะแสดงคำสั่งที่ `kit:apply` จะใช้สร้างชิ้นนี้

![การ์ดที่ ready และคำสั่งของมัน](images/structure-aggregate-ready.png)

### 3. เพิ่มสถานะ

กด **Add enum**:

- **Name:** `CrateStatus`
- **Aggregate:** `Crate`
- **Backing:** `string`
- ติ๊ก **A status: each case lists the cases it may become**
- **Cases, in order:** `Open` และ `Sealed` ใช้ **Add a case** เพิ่มแถวที่สอง ช่องค่าถ้าเว้นว่าง จะได้ชื่อ case แบบ snake case (`open`, `sealed`)

ใต้แต่ละ case มี **may become** ให้เลือกว่า case นี้เปลี่ยนไปเป็น case ไหนได้ ให้เลือก `Sealed` ใต้ `Open` ส่วน `Sealed` ไม่ต้องเลือกอะไร จะขึ้นว่า **· final**

![การเพิ่ม enum ที่เป็นสถานะ](images/structure-add-status-enum.png)

enum ชื่อ `*Status` ที่ระบุการเปลี่ยนสถานะไว้นับเป็น status (ดู [states.md](../resources/boost/guidelines/states.md))

### 4. เพิ่ม value object

กด **Add value object** ตั้ง **Name** เป็น `CrateLabel` และ **Aggregate** เป็น `Crate` จากนั้นเพิ่มสองช่องใน **Fields, in constructor order** คือ `code` ชนิด `string` และ `note` ชนิด `?string` ช่องชนิดจะแนะนำชนิดพื้นฐานและคลาสที่ context นี้ออกแบบไว้

![การเพิ่ม value object](images/structure-add-value-object.png)

บันทึก enum หรือ value object แล้ว สวิตช์ **Vocabulary** จะเปิดเอง และวาดมันไว้ข้าง aggregate

![มุมมอง Vocabulary](images/structure-vocabulary.png)

### 5. ออกแบบ exception

method ที่จะเพิ่มต่อไปปฏิเสธด้วย exception ให้ออกแบบ exception ก่อน แล้ว method จะเลือกจากรายการได้ และ `kit:apply` จะสร้างให้ในที่ของมัน กด **Add exception**:

- **Name:** `CrateSealedException`
- **Kind:** `Refusal of an aggregate`
- **Aggregate:** `Crate`

![การเพิ่ม exception](images/structure-add-exception.png)

ชนิดอื่นเป็นไปตาม [exceptions.md](../resources/boost/guidelines/exceptions.md):

- **Invalid value:** ค่าที่ FormRequest ควรกันไว้ตั้งแต่แรก สืบทอด `DomainValueException` อยู่ใน `Exceptions/` ของ aggregate ใน shared kernel มีได้แค่ชนิดนี้
- **Refusal of a use case:** สืบทอด `ApplicationException` อยู่ข้าง use case ที่เลือก หรืออยู่ที่ราก application ของ context ถ้า **Use case** เป็น None

exception ของ domain service ไม่ได้ออกแบบตรงนี้ ให้ติ๊ก **It has its own exception** ในฟอร์มของ service แทน

บันทึก exception แล้ว สวิตช์ **Exceptions** จะเปิดเอง และวาด exception แต่ละตัวโยงกับ aggregate หรือ use case ที่มันสังกัด

### 6. เพิ่ม method ให้ entity

กด **Add method**:

- **Entity:** `Crate`
- **Name:** `seal` (ชื่อที่ขึ้นต้นด้วย `assert` จะเป็น assertion แทน behaviour)
- **Parameters, in order:** `label` ชนิด `CrateLabel`
- **Throws:** `CrateSealedException` ช่องนี้มีรายการ exception ที่ออกแบบไว้ให้ `Crate` แบบชื่อเปล่า และของ shared kernel กับ aggregate อื่นแบบมี prefix (`Shared/X`, `Context/Aggregate/X`)

![การเพิ่ม method ให้ entity](images/structure-add-method.png)

บันทึก method แล้ว สวิตช์ **Behaviour** จะเปิดเอง และวาด entity พร้อม method ของมัน

![มุมมอง Behaviour](images/structure-behaviour.png)

ถ้าเปิด **Exceptions** ด้วย การ์ด exception จะโยงกับ `Crate` ที่ปฏิเสธด้วยมัน และกับ `seal` ที่ throw มัน panel ของมันแสดงทุก method ที่ throw มัน

![มุมมอง Exceptions](images/structure-exceptions.png)

### 7. เพิ่ม use case

กด **Add use case** สำหรับ `CreateCrate`:

- **Shape of __invoke():** `command`
- **Returns:** `string` คือ id ที่สร้างขึ้น
- **Options:** ติ๊ก **Mints ids through IdGenerator**
- **Repositories it injects:** ติ๊ก `Crate`

![การเพิ่ม use case](images/structure-add-use-case.png)

จากนั้นเพิ่ม `ListCrates` ซึ่งเป็น use case ที่หน้ารายการอ่านข้อมูล ติ๊ก **Reads a list through a query port** แต่ปล่อย shape ไว้เป็น `command` หน้าจอจะไม่ยอมบันทึก และแสดงเหตุผลไว้ใต้ช่องที่เกี่ยวข้อง:

![การบันทึกที่ถูกปฏิเสธ](images/structure-refused.png)

use case แบบรายการต้องรับ Command และคืน Result จึงต้องเปลี่ยน **Shape of __invoke()** เป็น `command-result` (Returns จะเปลี่ยนเป็น `result` เอง) แล้วกด **Add** อีกครั้ง ตอนนี้ context มีแบบครบแล้ว:

![context ที่ออกแบบเสร็จ](images/structure-context-designed.png)

### 8. สร้าง HTTP resource

กลับไปหน้าภาพรวม (คลิก breadcrumb **Structure**) แล้วกด **New HTTP resource** ชื่อคือชื่อ controller โดยตัด `Controller` ออก คือ `Crate` ซึ่งหมายถึง model `Crate` ด้วย ติ๊ก **It stands for no model** เฉพาะหน้าที่ไม่มี model เช่นหน้ารายงาน

![ฟอร์ม New HTTP resource](images/structure-new-resource.png)

ใน resource:

1. **Add method** `index` ตัวเลือก use case ที่ขึ้นมาจะขึ้นกับชื่อ method `index` จะมีให้เลือกเฉพาะแบบ `command-result` ให้ติ๊ก `ListCrates`
2. **Add method** `store` ที่เรียก `CreateCrate`
3. **Add page** `crates/index` ชนิด `table` หน้ารายการต้องอยู่ที่ `{list}/index` ตั้งชื่อตาม use case `List…` ของ index
4. ใน **Model and policy** ให้เอาติ๊ก **No policy** ออก แล้วพิมพ์ ability `create, viewAny`

![การเพิ่ม method ของ controller](images/structure-add-controller-method.png)

![การเพิ่มหน้า](images/structure-add-page.png)

![Model and policy](images/structure-model-and-policy.png)

การ์ดที่ต้องรอชิ้นอื่นจะขึ้นสถานะรอ เช่นหน้านี้รอ use case `Shipping/ListCrates` การ์ดของ context อื่น เช่น use case ที่ controller เรียก จะวาดเป็นเส้นประในชื่อ **Elsewhere** ดับเบิลคลิกเพื่อเปิด context นั้น

![HTTP resource ที่ออกแบบเสร็จ](images/structure-resource-designed.png)

หน้าภาพรวมตอนนี้จะมีทั้งสองอย่าง พร้อมจำนวน use case ที่ resource เรียก:

![หน้าภาพรวมที่มี context และ resource](images/structure-overview.png)

### 9. วางแผนและสร้างโค้ด

สถานะบนหน้าจอคือผลของ `kit:plan` แบบสด ๆ รันในเทอร์มินัลจะเห็นขั้นตอนเดียวกันเรียงลำดับ:

```
$ php artisan kit:plan
   INFO  Ready to run (8):
  aggregate Shipping/Crate  php artisan make:entity Crate --domain=Shipping/Crate
  enum Shipping/CrateStatus  php artisan make:enum CrateStatus --domain=Shipping/Crate --string --case=Open=open --case=Sealed=sealed --transitions --transition=Open:Sealed
  …
   INFO  Waiting (7):
  child Shipping/Crate/Lid .................. the root CrateEntity comes first
  …
   INFO  Done: 0 of 15 steps.
```

จากนั้นสร้างโค้ด:

```
$ php artisan kit:apply
  …
   INFO  Waiting (1):
  list page crates/index ........ fill in the keys of the ListCrates Row first
   INFO  Done: 13 of 14 steps.
```

`kit:apply` รันทุกขั้นที่ ready แล้ววางแผนใหม่ วนไปจนไม่เหลือขั้นที่ ready และหยุดที่ขั้นที่ต้องรอโค้ดที่คนต้องเขียนเอง รีโหลดหน้าจอแล้วการ์ดที่สร้างแล้วจะขึ้น **done** ชิ้นที่สร้างแล้วจะถูกล็อก panel จะมีปุ่ม **Replace** แทน Edit (ดูหัวข้อ "แทนที่ของที่สร้างแล้ว" ด้านล่าง)

![การ์ดที่สร้างแล้ว](images/structure-after-apply.png)

### 10. เขียนส่วนที่เหลือเอง แล้วสร้างอีกรอบ

ยังมีสองการ์ดที่ต้องจัดการเอง:

- **differs:** โค้ดถูกสร้างไม่ตรงกับที่ manifest บอก ในตัวอย่างนี้ `CratePolicy` มีแล้ว แต่ model ยังไม่ได้ผูกกับมัน `kit:apply` บอกบรรทัดที่ต้องเพิ่มไว้แล้ว คือ `#[UsePolicy(CratePolicy::class)]` บน `App\Models\Crate` และให้ลบ ability ที่ไม่ได้ออกแบบไว้ (`view`, `update`, `delete`) ออกจาก policy ที่สร้างมาด้วย

  ![การ์ดที่ differs](images/structure-differs.png)

- **waiting:** เหตุผลจะบอกว่าต้องเขียนอะไรก่อน หน้ารายการต้องรู้ key ของ `CrateListRow::toArray()` ก่อน จึงต้องเพิ่มคอลัมน์ที่ตารางจะแสดง

  ![การ์ดที่ waiting](images/structure-waiting.png)

รัน `php artisan kit:apply` อีกครั้ง มันจะสร้างหน้ารายการให้ เหลือเพียงสิ่งที่ไม่มี generator ไหนเขียนให้ คือบรรทัด `throw` ใน `seal()` ซึ่ง `kit:apply` แจ้งไว้ตอนจบใต้หัวข้อ **By hand**:

```
   WARN  By hand (1), where the code still differs from the manifest:
  [structure:matches] .kit/structure/Shipping.json: entities.Crate.seal.throws is [] in the code but ["CrateSealedException"] in the manifest
```

side panel ก็มีรายการ **By hand** สำหรับความต่างที่ไม่มีการ์ดให้แสดง ที่เจอบ่อยคือโค้ดที่ไม่มี manifest ไหนระบุไว้ เช่นในภาพนี้คือ use case ที่สร้างด้วย `make:use-case` นอกแบบ:

![รายการ By hand](images/structure-by-hand.png)

เขียน `throw` แล้ว การ์ดทุกใบจะขึ้น **done**:

![context ที่เสร็จสมบูรณ์](images/structure-context-done.png)

![HTTP resource ที่เสร็จสมบูรณ์](images/structure-resource-done.png)

ปิดท้ายด้วย `php artisan test --testsuite=Architecture` จากนี้ไป check `structure` จะคอยดูให้โค้ดกับ manifest ตรงกันเสมอ

## อ่านหน้าจอ

### มุมมอง

| มุมมอง | เปิดยังไง | แสดงอะไร |
|---|---|---|
| ภาพรวม | `/kit/structure` หรือ breadcrumb **Structure** | การ์ดหนึ่งใบต่อ context และต่อ HTTP resource พร้อมจำนวนในแต่ละสถานะ เส้นเชื่อมบอกว่า context ไหนใช้ context ไหน และ resource เรียก use case กี่ตัว |
| Context | ดับเบิลคลิก context หรือ `#context/{Name}` | aggregate, domain service, port และ use case พร้อม repository ที่แต่ละตัว inject **Vocabulary** เพิ่ม enum และ value object, **Behaviour** เพิ่ม entity ที่มี method และ **Exceptions** เพิ่ม exception |
| Shared kernel | `#context/Shared` | เฉพาะ enum, value object และ invalid value ที่ทุก context ใช้ได้ |
| HTTP resource | ดับเบิลคลิก resource หรือ `#resource/{Name}` | model, policy, controller, action และ page พร้อม use case ที่เรียก |

แถบที่อยู่ของเบราว์เซอร์จำมุมมองไว้ ลิงก์จึงเปิดมุมมองเดิมได้ และปุ่มย้อนกลับใช้ได้ปกติ แคนวาสเลื่อนและซูมได้ (ปุ่มควบคุมอยู่มุมซ้ายล่าง แผนที่ย่ออยู่มุมขวาล่าง) แต่ลากการ์ดไม่ได้ เพราะตำแหน่งคำนวณให้อัตโนมัติ

### การ์ด

การ์ดแต่ละชนิดมีรูปทรง สี และไอคอนของตัวเอง **Legend** ที่มุมซ้ายบนบอกชนิดที่วาดอยู่ในมุมมองนั้น และพับเก็บได้

| ชนิด | รูปทรง | สี |
|---|---|---|
| Context | ขอบสองชั้น | เรียบ |
| HTTP resource | กล่อง | คราม |
| Aggregate | กล่องมีแถบ | เหลืองอำพัน |
| Entity | กล่อง | เหลืองอำพัน |
| Domain service | แคปซูล | ม่วง |
| Port | หกเหลี่ยม | เขียวอมฟ้า |
| Use case | กล่อง | ฟ้า |
| List use case | กล่อง | เขียวมรกต |
| Enum | ป้ายแท็ก | บานเย็น |
| Status | ป้ายแท็ก มีไอคอนของตัวเอง | บานเย็น |
| Value object | มุมมน | เขียวมะนาว |
| Exception | กล่อง มีป้ายเตือน | แดง |
| Model | ทรงกระบอก | เทาอมฟ้า |
| Policy | โล่ | ชมพูกุหลาบ |
| Controller | กล่องมีหัว | คราม |
| Action | แคปซูล | ส้ม |
| Page | แผ่นกระดาษพับมุม ไอคอนตามชนิด (table, grid, form, page) | เทา |
| Elsewhere | เส้นประ | เทาจาง |

การ์ดแสดงได้สูงสุดหกบรรทัด เกินนั้นขึ้นว่า `… N more` status แสดงการเปลี่ยนสถานะ (`Open → Sealed`, `Sealed · final`) แทนค่า enum แสดง case, value object แสดง field และ entity แสดง method

คลิกการ์ดเพื่อเลือก คลิกพื้นที่ว่างเพื่อยกเลิก ดับเบิลคลิกการ์ดที่เปิดต่อได้ (context, resource หรือการ์ด Elsewhere) เพื่อไปยังมุมมองนั้น หรือกดปุ่ม **Open …** ใน panel ก็ได้

### สถานะ

การ์ดทุกใบมีสถานะตามที่ `kit:plan` ให้กับขั้นที่สร้างชิ้นนั้น:

| สถานะ | หมายความว่า | panel แสดง |
|---|---|---|
| **done** | สร้างแล้ว | รายละเอียดทั้งหมดของชิ้นนั้น |
| **ready** | `kit:apply` สร้างได้ทันที | คำสั่งที่จะรัน |
| **waiting** | ต้องมีอย่างอื่นก่อน อาจเป็นชิ้นอื่น หรือโค้ดที่คนต้องเขียน | สิ่งที่รออยู่ |
| **differs** | สร้างแล้วแต่ไม่ตรงกับที่ manifest บอก | ข้อความจาก check |

การ์ด Elsewhere ไม่มีสถานะ ให้ไปดูใน context ของมันเอง

### side panel

สำหรับการ์ดที่เลือก panel แสดงชนิดและชื่อ สถานะพร้อมเหตุผลหรือคำสั่ง รายละเอียดครบทุกบรรทัด (backing, case และการเปลี่ยนสถานะของ enum, พารามิเตอร์และ exception ของ method) และปุ่มที่ใช้ได้กับการ์ดนั้น: **Open …**, **Edit**, **Remove**, **Replace**, **Cancel replacement**

ด้านล่างสุดมี **By hand (N)** ซึ่งแสดงความต่างระหว่างโค้ดกับ manifest ที่ไม่มีการ์ดให้แสดง ที่เจอบ่อยคือคลาสในโค้ดที่ไม่มี manifest ไหนระบุไว้ แต่ละรายการบอกชื่อ check, สิ่งที่ต่าง และวิธีแก้ ซึ่งบ่อยครั้งคือ `php artisan kit:import --context=X --force`

## แก้แบบ

แถบด้านบนมีปุ่มเปิดฟอร์มตามมุมมองที่อยู่:

- **ภาพรวม:** New context, New HTTP resource
- **Context:** Add aggregate, Add domain service, Add port, Add use case, Add enum, Add value object, Add exception, Add method และสวิตช์ Vocabulary, Behaviour กับ Exceptions
- **Shared kernel:** Add enum, Add value object, Add exception
- **HTTP resource:** Add method, Add action, Add page, Model and policy

**Add** บันทึกทันทีในรูปแบบมาตรฐาน แล้ววาดไดอะแกรมใหม่ **Cancel** ปิดฟอร์มโดยไม่เขียนอะไร ถ้าเซิร์ฟเวอร์ปฏิเสธ จะแสดงเหตุผลใต้ช่องที่เกี่ยวข้อง และไม่มีอะไรถูกเขียน

### ชิ้นใน context

| ฟอร์ม | ช่อง | ถูกปฏิเสธเมื่อ |
|---|---|---|
| Aggregate | Name; Child entities, separated by commas; Repository | child ซ้ำกับ root, ลบ child ที่ยังมี method อยู่, เอาติ๊ก Repository ออกขณะที่ยังมีชิ้นอื่น inject อยู่ |
| Domain service | Name; Shape of handle(): `creates`, `data` หรือ `plain`; Builds the aggregate (สำหรับ `creates`); Exception: It has its own exception, `{Name}Exception`; Repositories it injects | repository หรือ aggregate ที่มันสร้างอยู่คนละ context ([layers.md](../resources/boost/guidelines/layers.md)) |
| Port | Name; Layer: `domain` หรือ `application`; Adapter ในรูป `Infra/{Folder}/{Prefix}{Port}` (ไม่ใส่ก็ได้) | adapter ไม่อยู่ในรูปนั้น |
| Use case | Name; Shape of __invoke(): `command-result`, `command` หรือ `plain`; Returns; Options: Mints ids through IdGenerator, Reads a list through a query port; Repositories it injects (เลือกจาก context ไหนก็ได้) | shape ที่รับ Command คืนค่าอื่นที่ไม่ใช่ `void`, `string`, `int` หรือ `result` ([handlers.md](../resources/boost/guidelines/handlers.md)), use case แบบรายการไม่ได้ชื่อ `List{Name}` คู่กับ `command-result` ([list-queries.md](../resources/boost/guidelines/list-queries.md)), repository ไม่มีอยู่จริง |
| Enum | Name; Aggregate; Backing: `string`, `int` หรือ `pure`; A status: each case lists the cases it may become; Cases, in order | case ไม่เป็น TitleCase หรือมีค่าซ้ำกัน, status ไม่ได้ชื่อ `*Status` หรือไม่มี case ไหนเปลี่ยนไปเป็น case อื่นได้เลย ([states.md](../resources/boost/guidelines/states.md)) |
| Value object | Name; Aggregate; Fields, in constructor order (ชื่อและชนิด) | field ไม่เป็น camelCase, ชนิดไม่ใช่ทั้งชนิดพื้นฐาน คลาสที่ manifest ออกแบบไว้ หรือคลาสที่มีในโค้ด |
| Exception | Name; Kind: Refusal of an aggregate, Invalid value หรือ Refusal of a use case (shared kernel รับเฉพาะ invalid value); Aggregate (สำหรับ refusal และ invalid value); Use case หรือ None (สำหรับ refusal ของ use case) | ชื่อไม่ลงท้ายด้วย `Exception`, aggregate หรือ use case ไม่ได้อยู่ใน context นี้ ([exceptions.md](../resources/boost/guidelines/exceptions.md)) |
| Method | Entity; Name; Parameters, in order (ชื่อและชนิด ใช้ `...Type` สำหรับตัวสุดท้ายที่เป็น variadic); Throws | ชื่อหรือพารามิเตอร์ไม่เป็น camelCase, ชนิดไม่รู้จัก, exception ไม่ได้ลงท้ายด้วย `Exception`, exception ของ shared kernel หรือ aggregate อื่นไม่ได้ออกแบบไว้ใน manifest ของมันและไม่มีในโค้ด |

ทุกชื่อเป็น StudlyCase (ยกเว้น method และ field เป็น camelCase) และต้องไม่ซ้ำทั้งใน manifest และในโค้ด ถ้าชื่อนั้นมีในโค้ดแล้ว แปลว่า manifest ตามไม่ทันโค้ด ให้รัน `php artisan kit:import --context=X --force` เพื่ออ่านกลับจากโค้ด

ในฟอร์ม enum และ value object กดลูกศรเพื่อเลื่อนแถวขึ้น กดกากบาทเพื่อลบแถว ลบ case แล้วการเปลี่ยนสถานะที่ไปถึง case นั้นจะหายไปด้วย แต่ถ้าเปลี่ยนชื่อ case การเปลี่ยนสถานะยังอยู่ครบ

### ชิ้นใน HTTP resource

| ฟอร์ม | ช่อง | ถูกปฏิเสธเมื่อ |
|---|---|---|
| New HTTP resource | Name, as its controller is named without Controller; It stands for no model | ชื่อซ้ำ หรือเป็น `AuditEntry` ของ kit เอง |
| Controller method | Method name; Use cases it calls (กรองตามชื่อ: `index` มีเฉพาะ `command-result`, `store` และ `update` ต้องรับ Command, `destroy` ต้องเป็น `plain`) | `index` ไม่ได้เรียก use case แบบ `command-result` ตัวเดียว, `store` หรือ `update` ไม่ได้เรียกตัวที่รับ Command ตัวเดียว, `destroy` ไม่ได้เรียกตัวแบบ plain ตัวเดียว ([form-pages.md](../resources/boost/guidelines/form-pages.md)) |
| Action | Verb; Acts on: One row, A selection of rows; Use case both call | ไม่ได้ติ๊กอะไรเลย, use case ของ bulk action ไม่ได้รับ Command และคืน `int` ([actions.md](../resources/boost/guidelines/actions.md)) |
| Page | Path under resources/js/pages; Kind: `table`, `grid`, `form` หรือ `page` | path ไม่ตรงกับที่ generator เขียน, หน้ารายการไม่มี `index`, หน้าฟอร์มไม่มี `store` หรือ `update` |
| Model and policy | Model หรือ No model; Policy abilities, separated by commas หรือ No policy | มี policy แต่ไม่มี model, model หรือ policy สร้างไปแล้ว |

### แก้และลบ

เลือกการ์ดแล้วกด **Edit** หรือ **Remove** การลบจะถามก่อนว่า *Remove X? It leaves the manifest of Y. Git keeps the file as it was.*

แก้ได้เฉพาะสิ่งที่โค้ดยังไม่มี:

- การ์ดที่สร้างแล้วจะขึ้นข้อความ *The code already has it, so it changes by replacing it.* แทนปุ่ม Edit และ Remove
- entity กับ controller ล็อกทีละ method: method ที่สร้างแล้วจะขึ้นว่า **built** ส่วนที่เหลือยังแก้ได้
- ชิ้นที่ยังมีชิ้นอื่นใช้อยู่ เปลี่ยนชื่อหรือลบไม่ได้ จนกว่าจะไม่มีใครใช้ เช่น aggregate ที่ use case inject อยู่หรือมี exception สังกัดอยู่, enum ที่ field ของ value object อ้างถึง, exception ที่ method throw อยู่, use case ที่มี refusal ของตัวเอง, `index` ขณะที่ยังมีหน้ารายการต้องใช้

ถ้า manifest บนดิสก์เปลี่ยนไปหลังจากเปิดหน้า (เช่น แก้จากอีกแท็บ, รัน `kit:import` หรือ `git checkout`) การบันทึกจะถูกปฏิเสธด้วยข้อความ *The manifest changed since this page loaded. Reload it, then make the change again.*

## แทนที่ของที่สร้างแล้ว

port ที่มี adapter แล้ว use case หรือ domain service ที่สร้างแล้ว เปลี่ยนได้ด้วยการแทนที่: สร้างตัวใหม่ข้างตัวเดิม ชี้โค้ดไปที่ตัวใหม่ แล้วค่อยเอาตัวเดิมออกเมื่อเทสต์ผ่าน aggregate, enum และ value object แทนที่ด้วยวิธีนี้ไม่ได้

1. เลือกการ์ดที่สร้างแล้ว กด **Replace** แล้วใส่ adapter ใหม่ (`Infra/{Folder}/{Prefix}{Port}`) หรือชื่อใหม่ use case แบบรายการต้องแทนด้วย `List…` อีกตัวที่เรียกสิ่งที่แสดงต่างออกไป เช่น `ListOwnCrates`

   ![ฟอร์ม Replace](images/structure-replace.png)

2. การ์ดใหม่จะมีบรรทัด `replaces …` และสถานะ **ready** ถ้าเป็น use case ทุก HTTP resource ที่เคยเรียกตัวเดิมจะเปลี่ยนไปเรียกตัวใหม่ แก้ตัวใหม่ได้ตามปกติ และกด **Cancel replacement** เพื่อยกเลิกได้ ตราบใดที่ตัวใหม่ยังไม่ถูกสร้าง

   ![การแทนที่ที่กำลังดำเนินอยู่](images/structure-replacing.png)

3. `php artisan kit:apply` สร้างตัวใหม่แล้วสลับเข้าไปแทน
4. `php artisan kit:retire` ลบตัวเดิมออก เมื่อไม่มีโค้ดไหนอ้างถึงแล้ว และ Architecture, Unit, Feature suite ผ่านทั้งหมด ถ้ายังมีที่อ้างถึงอยู่ คำสั่งจะบอกว่าอยู่ตรงไหน

รายละเอียดอยู่ใน [structure.md หัวข้อ Replacing what is built](../resources/boost/guidelines/structure.md)

## ไฟล์เบื้องหลังหน้าจอ

| ไฟล์ | เก็บอะไร |
|---|---|
| `.kit/structure/{Context}.json` | context หนึ่งตัว: aggregate, service, port, use case, enum, value object, entity |
| `.kit/structure/Shared.json` | enum และ value object ของ shared kernel |
| `.kit/structure/http/{Resource}.json` | HTTP resource หนึ่งตัว: model, controller, action, policy, page |

หน้าจอเขียนไฟล์ในรูปแบบมาตรฐาน (เรียง key และเยื้องสี่ช่อง) diff จึงแสดงเฉพาะส่วนที่เปลี่ยน ให้ commit ไฟล์เหล่านี้ไปพร้อมโค้ด เพราะ Git เป็นที่เดียวที่เก็บเวอร์ชันก่อนหน้าไว้

สี่คำสั่งทำงานกับไฟล์ชุดเดียวกัน:

- `kit:import` เขียน manifest จากโค้ด
- `kit:plan` แสดงขั้นตอนที่หน้าจอแสดงเป็นสถานะ
- `kit:apply` สร้างโค้ดตามขั้นตอนเหล่านั้น
- `kit:retire` ปิดการแทนที่

check `structure` ใน Architecture suite เทียบโค้ดกับ manifest ทั้งสองทาง

## แก้ปัญหา

| เจออะไร | สาเหตุและวิธีแก้ |
|---|---|
| `/kit/structure` ขึ้น 404 | `APP_ENV` ไม่ใช่ `local` หรือไม่มี `KitServiceProvider` ใน `bootstrap/providers.php` |
| หน้าขาว หรือ error เรื่อง Vite manifest | Vite ไม่ได้รันอยู่ หรือไม่มี `resources/js/kit/structure.tsx` ใน `vite.config.ts` ให้รัน `npm run dev` |
| context หรือ resource หายไปจากหน้าภาพรวม | JSON ของมันผิดรูปแบบ หน้าจอจะข้าม manifest ที่อ่านไม่ได้ ให้รัน `php artisan test --testsuite=Architecture` แล้ว check `structure:files` จะบอกว่าผิดตรงไหน |
| การ์ดขึ้น **differs** | โค้ดสร้างไม่ตรงกับแบบ อ่านข้อความใน panel แล้วแก้โค้ด (หรือแก้ manifest ถ้าชิ้นนั้นยังไม่ถูกสร้าง) |
| ชื่อถูกปฏิเสธเพราะมีในโค้ดแล้ว | manifest ตามไม่ทันโค้ด ให้รัน `php artisan kit:import --context=X --force` คำสั่งนี้อ่านทั้งไฟล์กลับจากโค้ด แบบที่ยังไม่ได้สร้างจะหายไป |
| *The manifest changed since this page loaded* | รีโหลดหน้าแล้วแก้ใหม่อีกครั้ง |
| ไม่มีปุ่ม **Edit** | ชิ้นนั้นสร้างแล้ว ให้เปลี่ยนด้วยการแทนที่ หรือแก้ที่โค้ด |
