You are working inside my existing Laravel project (`ereserve`).

GOAL:

Upgrade the existing facility/equipment photo feature so each facility or equipment can have MULTIPLE PHOTOS instead of only one photo.

The admin should be able to upload 3 to 4 photos for each facility/equipment.

On the USER side, those photos should appear as an image slideshow/carousel.

IMPORTANT:

DO NOT immediately start coding.

First inspect and understand the existing project.

The EXISTING PROJECT is the source of truth.

Do not assume:
- image column names
- facility table structure
- controller names
- model relationships
- storage paths
- facility/equipment architecture
- Blade file names
- existing CSS/JavaScript
- whether facilities and equipment use the same model
- whether Bootstrap is already being used

Adapt the implementation to the project's existing architecture.

==================================================
PHASE 1 — ANALYZE EXISTING PHOTO IMPLEMENTATION
==================================================

First inspect all code related to:

- Facility creation
- Facility editing
- Facility deletion
- Facility/equipment photos
- Facility model
- FacilityController
- migrations/database schema
- admin facility forms
- user facility cards/details
- image storage
- filesystem configuration
- public/storage
- Blade image rendering
- barangay scoping

Search the project for:

- <img
- image
- photo
- facility image fields
- Storage::
- store()
- storeAs()
- asset()
- storage/
- public/storage
- multipart/form-data

Determine the CURRENT photo flow:

Admin uploads photo
        ↓
Controller validation
        ↓
Laravel storage
        ↓
Database
        ↓
Facility model
        ↓
User Blade
        ↓
Browser image URL

Before changing anything, determine:

1. How many photos are currently supported.
2. What image-related database field currently exists.
3. Where uploaded photos are physically stored.
4. What path is stored in the database.
5. How the Blade view generates the image URL.
6. Whether facilities and equipment are the same entity/model or separate.
7. Whether Bootstrap carousel or another carousel library already exists in the project.

Do NOT create duplicate functionality if equivalent functionality already exists.

==================================================
PHASE 2 — MULTIPLE FACILITY PHOTOS
==================================================

Change the system so each facility/equipment can have:

MINIMUM:
1 photo

MAXIMUM:
4 photos

The admin should preferably be able to upload:

1, 2, 3, or 4 photos

Do not require exactly 4 photos unless the existing project requirements require it.

The form should support:

Facility Photos
[ Choose Files ]

Use a multiple file input.

Conceptually:

<input
    type="file"
    name="..."
    accept="image/*"
    multiple
>

Adapt the actual field names to the project.

The form must use:

enctype="multipart/form-data"

==================================================
PHASE 3 — VALIDATION
==================================================

Validate the uploads SERVER-SIDE.

Allow appropriate image formats such as:

- JPG
- JPEG
- PNG
- WEBP

Set a reasonable maximum file size per image.

Maximum:

4 photos per facility/equipment.

Do NOT rely only on JavaScript validation.

If more than 4 photos are submitted, return a proper Laravel validation error.

Use the project's existing validation style.

==================================================
PHASE 4 — DATABASE DESIGN
==================================================

Analyze the existing database BEFORE deciding how multiple photos should be stored.

Do NOT blindly create columns such as:

image1
image2
image3
image4

Prefer a normalized Laravel design if the current architecture supports it.

For example, if appropriate:

facilities
    id
    ...

facility_images
    id
    facility_id
    image_path
    sort_order
    created_at
    updated_at

with a relationship such as:

Facility
    hasMany FacilityImage

FacilityImage
    belongsTo Facility

However:

This is ONLY a conceptual example.

First inspect the existing schema and choose the solution that integrates cleanly with the project.

If a separate image table is appropriate, create the required migration/model/relationship.

If the project already has an equivalent photo/gallery structure, REUSE it instead.

Do not create unnecessary duplicate image fields.

==================================================
PHASE 5 — BACKWARD COMPATIBILITY
==================================================

The project may already have facilities using the current single-photo system.

DO NOT break existing facilities.

If existing facilities already have one image stored, preserve their ability to display that image.

If a migration/conversion is necessary, handle existing records safely.

Do not silently delete existing facility photos.

==================================================
PHASE 6 — IMAGE STORAGE
==================================================

Use Laravel's storage system correctly.

Store uploaded photos using the public disk or the project's existing equivalent.

Prefer a structure similar to:

storage/app/public/facilities/{facility-id}/

if appropriate.

Store portable RELATIVE paths in the database.

Do NOT store paths such as:

C:\Users\Tracy\ereserve\...

Do NOT hardcode:

http://127.0.0.1:8000

Do NOT store temporary upload paths.

Make sure the resulting browser URL correctly maps to the physical file.

Check the existing:

public/storage

link.

==================================================
PHASE 7 — ADMIN CREATE FACILITY
==================================================

On the admin Create Facility page:

Allow the admin to select up to 4 photos.

Keep the existing facility form design.

Do not redesign the entire page.

If practical, show previews of the selected photos before submission.

Example:

Facility Photos

[ Choose Files ]

Selected Photos:

[ Photo 1 ] [ Photo 2 ] [ Photo 3 ] [ Photo 4 ]

If JavaScript previews are added, they are only for UX.

Server-side validation remains required.

==================================================
PHASE 8 — ADMIN EDIT FACILITY
==================================================

The Edit Facility page must properly support the gallery.

Display the currently uploaded photos.

Example:

Current Photos:

[ Photo 1 ] [ Photo 2 ] [ Photo 3 ]

Allow the admin to:

- keep existing photos
- remove individual photos
- upload additional photos
- replace photos

The TOTAL number of retained + newly uploaded photos must never exceed 4.

Example:

Existing:
3 photos

Admin removes:
1 photo

Remaining:
2 photos

Admin may upload:
up to 2 more photos

Do not delete all existing photos merely because the admin edits another facility field.

==================================================
PHASE 9 — SAFE IMAGE DELETION
==================================================

When an admin removes an individual facility photo:

- verify that the photo belongs to that facility
- verify that the facility belongs to the admin's permitted barangay
- delete the database record safely
- delete the physical uploaded file where appropriate

Do not allow an admin to manipulate a photo ID in the URL/request and delete another barangay's facility photo.

Preserve the project's existing authorization/barangay architecture.

==================================================
PHASE 10 — USER PHOTO SLIDESHOW
==================================================

On the USER side, replace the single static facility image with a slideshow/carousel when multiple photos exist.

The slideshow should match the existing eReserve design.

Desired behavior:

-------------------------------------
|                                   |
|          FACILITY PHOTO           |
|                                   |
|  <                             >  |
|                                   |
|             ● ○ ○ ○               |
-------------------------------------

Provide:

- Previous arrow
- Next arrow
- Slide indicators/dots

If Bootstrap 5 is already used in this project, prefer using the existing Bootstrap Carousel rather than installing another unnecessary library.

If the project already uses another carousel implementation, reuse it.

Do NOT add a large dependency just for this feature unless necessary.

==================================================
PHASE 11 — CAROUSEL BEHAVIOR
==================================================

If there is:

1 PHOTO:
Display it normally.

The carousel controls do not need to appear.

2–4 PHOTOS:
Enable slideshow/carousel controls.

The user should be able to:

- click Previous
- click Next
- identify the current slide

Automatic sliding is optional.

Prefer manual navigation unless the project's existing UI pattern suggests otherwise.

==================================================
PHASE 12 — IMAGE DISPLAY
==================================================

All gallery photos should use a consistent display area.

Prevent different photo dimensions from breaking the facility card.

Use appropriate CSS such as:

object-fit: cover;

while preserving the existing card dimensions and responsive design.

Do not stretch/distort photos.

Maintain appropriate:

- border radius
- width
- height
- responsive behavior

based on the existing UI.

==================================================
PHASE 13 — FALLBACK IMAGE
==================================================

If a facility has NO uploaded photos:

Display the project's existing default/placeholder facility image.

Do NOT display:

- broken image icon
- empty image area
- alt text as the visible content

Handle missing physical files gracefully where practical.

==================================================
PHASE 14 — FACILITY CARDS
==================================================

Do not put four large images directly inside every facility card.

For list/card views, use the FIRST/PRIMARY photo as the facility thumbnail.

Example:

Facility Card
---------------------------------
|      Primary Facility Photo   |
---------------------------------
| Chairs                        |
| Available                     |
| View / Reserve                |
---------------------------------

Then use the full slideshow/gallery in the appropriate facility detail/reservation area.

If the current user page already has a suitable large facility image section, convert that section into the carousel.

==================================================
PHASE 15 — FACILITY VS EQUIPMENT
==================================================

The project contains facilities/equipment.

Do NOT assume these are separate database entities.

Inspect how the existing project distinguishes them.

If "equipment" is simply a facility category/type, the same gallery implementation should work for both.

If they are separate models, determine whether the photo implementation should be shared or separately related.

Do not unnecessarily duplicate the gallery system.

==================================================
PHASE 16 — BARANGAY SECURITY
==================================================

Preserve the existing barangay isolation.

Admins should only upload/edit/delete photos for facilities they are authorized to manage.

Users should only see facilities/equipment belonging to their permitted barangay according to the project's existing rules.

Do not weaken existing barangay checks.

==================================================
PHASE 17 — DO NOT CHANGE RESERVATION LOGIC
==================================================

This feature is primarily about facility/equipment photos.

Do NOT break or unnecessarily modify:

- reservation creation
- Pending / Accepted / Rejected
- Booked
- In Use
- Available
- Partially Booked
- Fully Booked
- facility Unavailable status
- overlapping reservation requests
- calendar
- duplicate reservation protection
- user management
- authentication
- barangay scoping

==================================================
PHASE 18 — VERIFY
==================================================

Do not stop after modifying code.

Verify these scenarios.

TEST A — ONE PHOTO

1. Admin creates facility.
2. Uploads 1 photo.
3. Save.
4. User sees the correct photo.
5. No broken image appears.

TEST B — FOUR PHOTOS

1. Admin creates/edits facility.
2. Uploads 4 photos.
3. Save.
4. Confirm all four files are stored.
5. Confirm all four database records/paths are correct.
6. User opens the facility.
7. Slideshow contains all four photos.
8. Previous/Next controls work.

TEST C — TOO MANY

Attempt to upload 5 photos.

Expected:

Laravel rejects the request with an appropriate validation error.

TEST D — EDIT

Facility currently has 4 photos.

Admin removes one.

Expected:

3 remain.

Admin uploads another.

Expected:

Total becomes 4.

TEST E — EDIT WITHOUT PHOTOS

Edit only the facility name/description/status.

Expected:

Existing photos remain unchanged.

TEST F — NO PHOTO

Facility has no uploaded photos.

Expected:

Default placeholder displays.

TEST G — SECURITY

Attempt to manipulate a facility/photo ID belonging to another barangay.

Expected:

Request is rejected according to the project's existing authorization rules.

==================================================
FINAL REQUIREMENT
==================================================

Analyze first.

Then implement.

The EXISTING eReserve project is the source of truth.

Do not force the examples in this prompt onto the project if the actual architecture uses different:

- names
- relationships
- fields
- routes
- controllers
- UI structure

Make the minimum clean changes necessary.

Prefer reusing existing code over duplicating it.

After implementation report:

1. Existing photo implementation you found.
2. Database design chosen for multiple photos and why.
3. Migration(s) created.
4. Model/relationship changes.
5. Controller changes.
6. Blade changes.
7. JavaScript/CSS changes.
8. Storage location and database path format.
9. How old single-photo facilities remain supported.
10. How the 4-photo limit is enforced.
11. How edit/remove works.
12. How barangay authorization is protected.
13. Verification results.

Do not claim it works unless the relevant code paths were actually verified.