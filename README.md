# DagLab Paragraphs

Reports and cleanup tools for paragraphs: see which paragraph types are used
and where, look at any paragraph on its own, and clean up the paragraphs and
old revisions that are left behind as content changes.

Everything is worked out from the database each time you open a page. There
is nothing to build or refresh first, and the reports stay correct after a
cache clear.

## Where to find it

**Reports > Paragraph Usage** (`/admin/reports/daglab/paragraphs`), with two
tabs: **In Use** and **Unused**.

The module also makes the **Paragraph types** admin page
(Structure > Paragraph types) list every type on one page, instead of 50 at a
time.

## In Use

Every paragraph type shown on at least one published page, with:

- **# of pages**: how many published pages show the type. Paragraphs nested
  inside other paragraphs count for the page they end up on. A type inside a
  published block that is placed in the block layout shows as, for example,
  "3 (+1 placed block)", since that block appears site-wide rather than on
  particular pages.
- **Orphans**: how many more paragraphs of the type exist in the database
  that nothing references. See [What is an orphan?](#what-is-an-orphan)
- **Example**: one of the pages showing the type.

Click a column heading to sort. Sorting by **Orphans** is a quick way to find
the types with the most to clean up.

Click a type's name for the list of every published page it is on.

Each row's **Operations** menu has:

- **View example**: the paragraph on its own. See
  [Viewing a paragraph](#viewing-a-paragraph).
- **Delete orphans**: when the type has any.
- **Manage type**: the paragraph type's settings.

## Unused

Paragraph types not shown on any published page, in three groups.

### Never Used Paragraphs

The type exists in configuration, but no paragraph of the type was ever
created, or all of them have since been deleted. There is no content to clean
up, so a developer can remove the type.

### Orphaned Paragraphs

Paragraphs of the type exist, but every one of them is an orphan. **Delete
orphans** removes them, after which the type moves to Never Used.

### Existing Paragraphs without Published Revisions

Something still references these types, but not in a way that shows them on a
published page. **Referenced by** says what, for example "Unpublished: 2
content items" or "Older revisions only: 7 content items". A warning in bold
means a published block holding the type is placed in the block layout or
embedded on a published page, so it may still be visible on the site.

**Details** opens the full list: each page or block that references the type,
whether it is published, and which of its revisions hold the type (its
current revision, older ones, or a newer draft). Each row has links to view
the paragraph as that page holds it, to the page's revisions, and to edit or
delete the page.

What to do depends on what is holding the type:

- **An unpublished page.** Decide whether the page is still needed. If not,
  delete it the usual way. Its paragraphs become orphans, ready for **Delete
  orphans**.
- **Only older revisions of pages.** Nothing on the site shows these, but the
  page history keeps them. If you want to retire the type, use **Delete older
  revisions**, then **Delete orphans**.
- **A newer draft.** Someone has unpublished work in progress that uses the
  type. Leave it alone, or talk to them.

## Cleaning up

Both cleanup actions ask for confirmation first, show exactly how much they
will delete, and run in the background with a progress bar. Each run is
recorded in the site log. **Neither can be undone**, so take a database backup
before large cleanups.

### Delete orphans

Permanently deletes the orphans of one paragraph type. Paragraphs that are
still referenced anywhere, by any revision of any page or block, are never
touched. The orphans are worked out again at the moment you confirm, so
anything that became referenced in the meantime is left alone.

### Delete older revisions

Permanently deletes the older revisions of pages and blocks that reference
one paragraph type.

- A page's current revision is never deleted, and neither is a newer draft.
- Each revision is deleted in full, in every language: the page's history for
  everything in that revision is lost, not just the paragraph.
- You still need the usual permission to delete each revision. Any you could
  not delete from the page's Revisions tab are skipped and reported.

Afterwards the type's paragraphs show as orphans. Use **Delete orphans** to
finish. Drupal may also remove some of them on its own during cron.

## Viewing a paragraph

**View example**, and **View paragraph** on the details pages, open a single
paragraph in the site's normal theme, so it looks as it does to visitors.
Below it you will find:

- The paragraph's type, ID, created date, status, and which revision is shown.
- Its **stored parent**: the page or paragraph the paragraph says it belongs
  to. A warning appears when that parent no longer actually uses it, which is
  common for orphans.
- **Where it is used**: what really references the paragraph, and through
  which revisions. An orphan shows "Nothing references this paragraph".

Some components need the page they are on to display properly, for example
maps that use the page's location. Those may look empty on their own.

## What is an orphan?

An orphan is a paragraph that nothing references at all: not the current
revision of any page or block, not an older revision, and not a draft. Nothing
on the site can show it, and reverting a page to any of its revisions would
not bring it back.

Orphans build up naturally as content is edited and old paragraphs are
replaced. They are safe to delete.

A paragraph also stores the page it originally belonged to, but that is kept
even after the page stops using the paragraph. These reports ignore it and
check what actually references each paragraph.

## Permissions

| Permission | Allows |
|---|---|
| View site reports (core) | Using all the reports and viewing paragraphs. |
| Delete orphaned paragraphs | Delete orphans. |
| Delete older revisions holding unused paragraphs | Delete older revisions. Each revision also needs the usual permission to delete it. |

The two delete permissions are marked as restricted. Only the administrator
role has them until you grant them to another role.
