<?php
declare(strict_types=1);

$ROOT  = __DIR__;
$INDEX = $ROOT . '/cache/index.json';

/* ---- E-reader detection ---- */
function is_tolino(): bool {
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    if (stripos($ua, 'Tolino') !== false) return true;
    if (stripos($ua, 'WebKit/53') !== false && stripos($ua, 'Chrome') === false) return true;
    return false;
}

$IS_TOLINO = is_tolino();

/* ---- Load cache ---- */
$books = [];
if (is_file($INDEX)) {
    $data = json_decode((string)@file_get_contents($INDEX), true);
    if (is_array($data)) {
        $books = array_values($data);
    }
}

function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

function format_size(int $bytes): string {
    if ($bytes >= 1048576) return number_format($bytes / 1048576, 1, '.', '') . ' MB';
    if ($bytes >= 1024) return round($bytes / 1024) . ' KB';
    return $bytes . ' B';
}

function format_date(?int $mtime): string {
    return $mtime ? date('M j, Y', $mtime) : '';
}

/* ---- Security headers ---- */
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline'; script-src 'self' 'unsafe-inline'; connect-src 'self'; object-src 'none'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'");

$booksJson = json_encode(
    $books,
    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
);
if ($booksJson === false) $booksJson = '[]';

/* =========================================================
   ================ E-READER UI ============================
   Kept deliberately plain: e-ink, no JS, no webfonts,
   no external requests.
   ========================================================= */
if ($IS_TOLINO):
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>EPUB Library</title>
<style>
body { font-family: Georgia, serif; background:#fff; color:#000; margin:0 }
h1 { font-size:1.35em; padding:.8em .8em .5em; margin:0; border-bottom:2px solid #000 }
.count { margin:0; padding:0 .95em .75em; font-size:.85em; border-bottom:1px solid #000 }
.book { padding:.8em; border-bottom:1px solid #999; overflow:hidden }
.cover { float:left; width:64px; height:96px; margin-right:.8em; border:1px solid #000 }
.cover img { width:64px; height:96px; display:block }
.title { font-weight:bold; line-height:1.25 }
.author { font-size:.9em; margin-top:.15em }
.meta { font-size:.8em; margin:.35em 0 }
.empty { padding:.8em; font-size:.9em }
code { font-family: monospace }
a { color:#000; text-decoration:underline }
</style>
</head>
<body>

<h1>EPUB Library</h1>
<p class="count"><?=count($books)?> <?=count($books) === 1 ? 'book' : 'books'?></p>

<?php if (!$books): ?>
<p class="empty">No books found yet. Add EPUB files to the <code>books</code> folder and rescan from a computer.</p>
<?php endif; ?>

<?php foreach ($books as $b): ?>
<div class="book">

  <?php if (!empty($b['cover'])): ?>
  <div class="cover">
    <img src="<?=h($b['cover'])?>" alt="">
  </div>
  <?php endif; ?>

  <div class="title"><?=h($b['title'] ?? $b['file'])?></div>

  <?php if (!empty($b['author'])): ?>
    <div class="author"><?=h($b['author'])?></div>
  <?php endif; ?>

  <div class="meta">
    <?=h(format_size((int)($b['size'] ?? 0)))?><?php if (!empty($b['mtime'])): ?> &middot; <?=h(format_date((int)$b['mtime']))?><?php endif; ?>
  </div>

  <div>
    <a href="download.php?f=<?=urlencode($b['file'])?>">Download EPUB</a>
  </div>

</div>
<?php endforeach; ?>

</body>
</html>
<?php
exit;
endif;

/* =========================================================
   ================ DESKTOP UI =============================
   ========================================================= */
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="theme-color" content="#2F3A2C">
<meta name="description" content="A self-hosted EPUB library for e-readers and browsers.">
<title>EPUB Library</title>
<link rel="icon" href="assets/favicon.svg" type="image/svg+xml">
<style>
:root{
  --paper:#EAE3D3;
  --card:#F7F3E8;
  --ink:#211F1A;
  --muted:#7A7364;
  --rule:#D8CFBA;
  --moss:#46573F;
  --brass:#B08A3E;
  --focus:#33526E;
  --wood:#8A5F38;
  --glow:rgba(255,255,255,.55);
  --grain:rgba(33,31,26,.022);
  --shadow:rgba(33,31,26,.35);
}
@media (prefers-color-scheme: dark){
  :root{
    --paper:#171511;
    --card:#26241D;
    --ink:#EFE9DC;
    --muted:#A69C86;
    --rule:#3A362B;
    --moss:#8FAE85;
    --brass:#C9A45C;
    --focus:#7FA8D4;
    --wood:#6B4728;
    --glow:rgba(255,255,255,.045);
    --grain:rgba(255,255,255,.025);
    --shadow:rgba(0,0,0,.6);
  }
}

*{box-sizing:border-box}
html{scroll-behavior:smooth}
body{
  margin:0;
  min-height:100vh;
  background:
    radial-gradient(900px 520px at 12% -8%, var(--glow), transparent 62%),
    repeating-linear-gradient(0deg, transparent 0 31px, var(--grain) 31px 32px),
    var(--paper);
  color:var(--ink);
  font-family:system-ui, -apple-system, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
  -webkit-font-smoothing:antialiased;
}
a{color:inherit}
:focus-visible{outline:2px solid var(--focus); outline-offset:3px}
@media (prefers-reduced-motion: reduce){
  html{scroll-behavior:auto}
  *{animation-duration:.001s !important; transition-duration:.001s !important}
}
.sr-only{
  position:absolute; width:1px; height:1px; padding:0; margin:-1px;
  overflow:hidden; clip:rect(0,0,0,0); white-space:nowrap; border:0;
}
.skip{
  position:absolute; left:1rem; top:-4rem; z-index:30;
  padding:.6rem 1rem; background:var(--card); color:var(--ink);
  border:1px solid var(--brass); text-decoration:none;
}
.skip:focus{top:1rem}

/* ---------- masthead ---------- */
.masthead{
  position:relative;
  background:
    repeating-linear-gradient(90deg, rgba(255,255,255,.02) 0 2px, transparent 2px 9px),
    radial-gradient(120% 160% at 100% 0%, rgba(176,138,62,.22), transparent 55%),
    linear-gradient(180deg, #303B2B 0%, #242D21 55%, #1C2319 100%);
  color:#F4EDDC;
  border-bottom:3px solid var(--brass);
  box-shadow:0 12px 30px -12px rgba(0,0,0,.45);
}
.masthead-inner{
  max-width:1180px; margin:0 auto;
  padding:2.1rem 2rem 1.15rem;
  display:flex; align-items:flex-end; justify-content:space-between;
  gap:2rem; flex-wrap:wrap;
}
.brand{display:flex; align-items:flex-end; gap:1.2rem; min-width:0}
.brand-copy .eyebrow{
  margin:0 0 .45rem; color:var(--brass);
  font-size:.68rem; font-weight:600; letter-spacing:.22em; text-transform:uppercase;
}
.brand-copy h1{
  margin:0; font-family:Georgia, 'Iowan Old Style', 'Palatino Linotype', 'Book Antiqua', ui-serif, serif;
  font-size:2.9rem; line-height:.95; font-weight:600; letter-spacing:-.02em;
}
.brand-copy .tagline{margin:.55rem 0 0; color:#C9BFA8; font-size:.92rem}

.shelf-mark{
  position:relative; flex:0 0 auto;
  display:flex; align-items:flex-end; gap:3px;
  width:84px; height:54px; padding:0 5px 10px;
}
.mini-book{
  width:var(--w); height:var(--h);
  background:linear-gradient(180deg, rgba(255,255,255,.3), transparent 20%), var(--c);
  border-radius:1px 1px 0 0;
  box-shadow:inset -2px 0 0 rgba(0,0,0,.22), inset 1px 0 0 rgba(255,255,255,.12);
}
.mini-book:nth-child(2){transform:rotate(-2deg); transform-origin:bottom}
.mini-book:nth-child(4){transform:rotate(1.5deg); transform-origin:bottom}
.mini-shelf{
  position:absolute; left:0; right:0; bottom:0; height:8px;
  background:linear-gradient(180deg, #B98A57, #8A5F38 55%, #5E3B21);
  border-radius:2px;
  box-shadow:0 4px 7px rgba(0,0,0,.28);
}

.controls{display:flex; gap:.7rem; align-items:center; flex-wrap:wrap}
.field{position:relative; display:flex; align-items:center}
.field input, .field select{
  font:inherit; font-size:.88rem;
  padding:.62rem .85rem;
  background:#F7F3E8; color:#211F1A;
  border:1px solid #C9A45C;
  border-radius:2px;
  box-shadow:inset 0 1px 0 rgba(255,255,255,.65), 0 2px 0 rgba(0,0,0,.12);
}
.field select{cursor:pointer}
.search-field input{width:250px; padding-left:2.2rem}
.search-field svg{
  position:absolute; left:.72rem; top:50%; transform:translateY(-50%);
  width:15px; height:15px; color:#7A7364; pointer-events:none;
}
.field input::placeholder{color:#8A8272}

.masthead-foot{
  max-width:1180px; margin:0 auto;
  padding:0 2rem 1.1rem;
  display:flex; align-items:center; justify-content:space-between;
  gap:1rem; flex-wrap:wrap;
}
.count{margin:0; color:#BFB49C; font-size:.82rem}
.rescan{display:flex; align-items:center; gap:.8rem; flex-wrap:wrap}
.rescan button{
  display:inline-flex; align-items:center; gap:.45rem;
  font:inherit; font-size:.76rem; font-weight:600;
  letter-spacing:.08em; text-transform:uppercase;
  padding:.5rem .75rem;
  color:#F4EDDC; background:transparent;
  border:1px solid rgba(201,164,92,.65); border-radius:2px;
  cursor:pointer;
  transition:background .2s, border-color .2s;
}
.rescan button:hover:not(:disabled){background:rgba(201,164,92,.16); border-color:#C9A45C}
.rescan button:disabled{opacity:.55; cursor:wait}
.status{font-size:.78rem; color:#BFB49C}

/* ---------- shelves ---------- */
.shelf{
  max-width:1180px; margin:0 auto;
  padding:2.8rem 2rem 6rem;
  display:grid;
  grid-template-columns:repeat(auto-fill, minmax(180px, 1fr));
  column-gap:1.4rem;
  row-gap:2.6rem;
}

.book{
  position:relative;
  display:flex; flex-direction:column;
  padding-bottom:1.4rem;
  isolation:isolate;
}
.cover-shell{
  position:relative;
  aspect-ratio:2/3;
  overflow:hidden;
  border-radius:2px 5px 5px 2px;
  background:var(--rule);
  box-shadow:0 14px 22px -14px var(--shadow), inset 0 0 0 1px rgba(33,31,26,.14);
  transition:transform .25s ease, box-shadow .25s ease;
}
.cover-shell::before{
  content:""; position:absolute; inset:0 auto 0 0; width:10px; z-index:2;
  background:linear-gradient(90deg, rgba(0,0,0,.35), rgba(0,0,0,.08) 55%, transparent);
  pointer-events:none;
}
.cover-shell::after{
  content:""; position:absolute; inset:0; z-index:2; pointer-events:none;
  background:linear-gradient(120deg, rgba(255,255,255,.16), transparent 38%);
}
.cover{width:100%; height:100%; object-fit:cover; display:block}
.cover-fallback{
  display:flex; align-items:center; justify-content:center;
  width:100%; height:100%;
  background:linear-gradient(135deg, rgba(255,255,255,.2), transparent 45%), var(--spine);
  color:#F7F3E8;
  font-family:Georgia, 'Iowan Old Style', 'Palatino Linotype', 'Book Antiqua', ui-serif, serif;
  font-size:2.6rem;
}
.book:hover .cover-shell{
  transform:translateY(-7px) rotate(-.5deg);
  box-shadow:0 22px 30px -16px var(--shadow), inset 0 0 0 1px rgba(33,31,26,.16);
}
.book::after{
  content:"";
  position:absolute; z-index:-1;
  left:-.7rem; right:-.7rem; bottom:0;
  height:.8rem;
  background:
    linear-gradient(180deg, rgba(255,255,255,.2), transparent 32%),
    linear-gradient(180deg, #B98A57 0%, #8A5F38 55%, #5E3B21 100%);
  border-radius:2px;
  box-shadow:0 5px 9px -3px var(--shadow);
}

.details{display:flex; flex-direction:column; gap:.15rem; padding:.9rem .1rem 0}
.book h2{
  margin:0; min-height:2.6em;
  font-family:Georgia, 'Iowan Old Style', 'Palatino Linotype', 'Book Antiqua', ui-serif, serif;
  font-size:1.02rem; font-weight:500; line-height:1.3;
  display:-webkit-box; -webkit-box-orient:vertical; -webkit-line-clamp:2;
  overflow:hidden;
}
.book .author{
  margin:0; min-height:1.25em;
  font-size:.84rem; color:var(--muted);
  white-space:nowrap; overflow:hidden; text-overflow:ellipsis;
}
.book .meta{margin:.1rem 0 0; font-size:.73rem; color:var(--muted); letter-spacing:.02em}
.download{
  margin-top:.6rem; align-self:flex-start;
  display:inline-flex; align-items:center; gap:.35rem;
  font-size:.76rem; font-weight:600;
  letter-spacing:.08em; text-transform:uppercase;
  color:var(--moss); text-decoration:none;
  border-bottom:1px solid transparent;
}
.download svg{width:13px; height:13px; transition:transform .2s}
.download:hover{border-bottom-color:var(--moss)}
.download:hover svg{transform:translateY(2px)}

/* ---------- empty / no-js ---------- */
.empty{
  grid-column:1/-1;
  display:flex; flex-direction:column; align-items:center; gap:.8rem;
  padding:4rem 1rem; text-align:center; color:var(--muted);
}
.empty-icon{width:54px; height:54px; opacity:.6}
.empty h2{margin:0; font-family:Georgia, 'Iowan Old Style', 'Palatino Linotype', 'Book Antiqua', ui-serif, serif; font-size:1.4rem; font-weight:500; color:var(--ink)}
.empty p{max-width:34rem; margin:0; font-size:.92rem; line-height:1.6}
.empty code{background:var(--card); border:1px solid var(--rule); padding:.1rem .35rem; border-radius:2px}
.no-js{max-width:1180px; margin:0 auto; padding:0 2rem 4rem}
.no-js h2{font-family:Georgia, 'Iowan Old Style', 'Palatino Linotype', 'Book Antiqua', ui-serif, serif; font-weight:500}
.plain-list{list-style:none; padding:0; margin:1rem 0 0; display:flex; flex-direction:column; gap:.5rem}
.plain-list a{color:var(--moss); text-decoration:underline}
.muted{color:var(--muted)}

@media (max-width:760px){
  .masthead-inner{padding:1.5rem 1.2rem 1rem; gap:1.2rem}
  .brand{gap:.9rem}
  .shelf-mark{width:66px; height:46px}
  .brand-copy h1{font-size:2.2rem}
  .brand-copy .tagline{font-size:.86rem}
  .controls{width:100%}
  .search-field{flex:1 1 100%}
  .search-field input{width:100%}
  .field select{width:100%}
  .masthead-foot{padding:0 1.2rem 1rem}
  .shelf{
    padding:2rem 1.2rem 4rem;
    grid-template-columns:repeat(auto-fill, minmax(140px,1fr));
    column-gap:1rem; row-gap:2.2rem;
  }
  .book::after{left:-.5rem; right:-.5rem}
  .book h2{font-size:.95rem}
  .no-js{padding:0 1.2rem 3rem}
}
@media (max-width:420px){
  .shelf{grid-template-columns:repeat(2, minmax(0,1fr))}
}
</style>
</head>
<body>

<a class="skip" href="#grid">Skip to the books</a>

<header class="masthead">
  <div class="masthead-inner">
    <div class="brand">
      <div class="shelf-mark" aria-hidden="true">
        <span class="mini-book" style="--w:6px;--h:30px;--c:#7A3428"></span>
        <span class="mini-book" style="--w:8px;--h:38px;--c:#33526E"></span>
        <span class="mini-book" style="--w:7px;--h:34px;--c:#9C7A2A"></span>
        <span class="mini-book" style="--w:10px;--h:42px;--c:#46573F"></span>
        <span class="mini-book" style="--w:6px;--h:28px;--c:#5B3A5C"></span>
        <span class="mini-shelf"></span>
      </div>
      <div class="brand-copy">
        <p class="eyebrow">A personal reading room</p>
        <h1>EPUB Library</h1>
        <p class="tagline">Your books, ready for the e-reader.</p>
      </div>
    </div>

    <div class="controls">
      <label class="field search-field">
        <span class="sr-only">Search by title or author</span>
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
          <circle cx="11" cy="11" r="7"></circle>
          <path d="m20 20-3.5-3.5"></path>
        </svg>
        <input id="q" type="search" placeholder="Search title or author" autocomplete="off">
      </label>

      <label class="field">
        <span class="sr-only">Sort books</span>
        <select id="sort">
          <option value="title">Title A–Z</option>
          <option value="author">Author A–Z</option>
          <option value="newest">Recently added</option>
          <option value="size">Largest first</option>
        </select>
      </label>
    </div>
  </div>

  <div class="masthead-foot">
    <p class="count" id="count-text" aria-live="polite"><?=count($books)?> <?=count($books) === 1 ? 'book' : 'books'?></p>
    <div class="rescan">
      <button id="rescan" type="button">
        <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
          <path d="M21 12a9 9 0 1 1-2.64-6.36"></path>
          <path d="M21 3v6h-6"></path>
        </svg>
        Rescan shelf
      </button>
      <span class="status" id="rescan-status" role="status" aria-live="polite"></span>
    </div>
  </div>
</header>

<main id="grid" class="shelf" aria-label="Books"></main>

<noscript>
  <section class="no-js">
    <h2>JavaScript is off</h2>
    <p>You can still download every book directly:</p>
    <?php if ($books): ?>
      <ul class="plain-list">
        <?php foreach ($books as $b): ?>
          <li>
            <a href="download.php?f=<?=urlencode($b['file'])?>"><?=h($b['title'] ?? $b['file'])?></a>
            <?php if (!empty($b['author'])): ?><span class="muted">— <?=h($b['author'])?></span><?php endif; ?>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php else: ?>
      <p>No books yet.</p>
    <?php endif; ?>
  </section>
</noscript>

<script>
const books = <?=$booksJson?>;
const grid = document.getElementById('grid');
const countText = document.getElementById('count-text');

const collator = new Intl.Collator(undefined, { numeric: true, sensitivity: 'base' });

function esc(s){
  return (s || '').replace(/[&<>"']/g, c => ({
    '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;'
  }[c]));
}

function normalise(s){
  return (s || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();
}

const SPINES = ['#46573F', '#7A3428', '#33526E', '#9C7A2A', '#5B3A5C', '#2B2B2B'];
function spineFor(key){
  let hash = 0;
  for (const ch of (key || '')) hash = (hash * 31 + ch.charCodeAt(0)) >>> 0;
  return SPINES[hash % SPINES.length];
}

function initials(title){
  return (title || '?').trim().slice(0, 1).toUpperCase();
}

function formatBytes(n){
  if (!n) return '0 KB';
  if (n >= 1048576) return (n / 1048576).toFixed(1).replace(/\.0$/, '') + ' MB';
  return Math.round(n / 1024) + ' KB';
}

function formatDate(ts){
  if (!ts) return '';
  return new Date(ts * 1000).toLocaleDateString(undefined, {
    year: 'numeric', month: 'short', day: 'numeric'
  });
}

const BOOK_ICON = `<svg class="empty-icon" viewBox="0 0 64 64" fill="none" aria-hidden="true">
  <path d="M13 19c6.4-2.2 12.8-1.8 19 2.6v27c-6.2-4.4-12.6-4.8-19-2.6V19Z" stroke="currentColor" stroke-width="2"/>
  <path d="M51 19c-6.4-2.2-12.8-1.8-19 2.6v27c6.2-4.4 12.6-4.8 19-2.6V19Z" stroke="currentColor" stroke-width="2"/>
  <path d="M32 21.6v27" stroke="currentColor" stroke-width="2"/>
</svg>`;

function render(){
  const q = normalise(document.getElementById('q').value);
  const sort = document.getElementById('sort').value;

  const list = books.filter(b =>
    normalise(b.title).includes(q) || normalise(b.author).includes(q)
  );

  list.sort((a, b) => {
    if (sort === 'newest') return (b.mtime || 0) - (a.mtime || 0);
    if (sort === 'size') return (b.size || 0) - (a.size || 0);
    return collator.compare(a[sort] || '', b[sort] || '');
  });

  const total = books.length;
  countText.textContent = total + (total === 1 ? ' book' : ' books')
    + (q ? ' · ' + list.length + ' shown' : '');

  if (list.length === 0){
    grid.innerHTML = books.length === 0
      ? `<div class="empty">${BOOK_ICON}
           <h2>Your shelf is empty</h2>
           <p>Drop EPUB files into the <code>books/</code> folder, then choose <strong>Rescan shelf</strong>.</p>
         </div>`
      : `<div class="empty">${BOOK_ICON}
           <h2>Nothing matches your search</h2>
           <p>Try a different title or author.</p>
         </div>`;
    return;
  }

  const frag = document.createDocumentFragment();

  for (const b of list){
    const title = b.title || b.file;
    const card = document.createElement('article');
    card.className = 'book';
    card.style.setProperty('--spine', spineFor(title));

    card.innerHTML = `
      <div class="cover-shell">
        ${b.cover
          ? `<img class="cover" src="${esc(b.cover)}" alt="Cover of ${esc(title)}" loading="lazy">`
          : `<div class="cover-fallback" aria-hidden="true">${esc(initials(title))}</div>`}
      </div>
      <div class="details">
        <h2 title="${esc(title)}">${esc(title)}</h2>
        <p class="author">${esc(b.author || 'Unknown author')}</p>
        <p class="meta">${formatBytes(b.size)}${b.mtime ? ' · ' + formatDate(b.mtime) : ''}</p>
        <a class="download" href="download.php?f=${encodeURIComponent(b.file)}" aria-label="Download ${esc(title)} (EPUB)">
          Download
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M12 3v12"></path>
            <path d="m7 10 5 5 5-5"></path>
            <path d="M5 21h14"></path>
          </svg>
        </a>
      </div>
    `;
    frag.appendChild(card);
  }

  grid.innerHTML = '';
  grid.appendChild(frag);
}

let searchTimer;
document.getElementById('q').addEventListener('input', () => {
  clearTimeout(searchTimer);
  searchTimer = setTimeout(render, 120);
});
document.getElementById('sort').addEventListener('change', render);

/* ---- AJAX rescan ---- */
const rescanBtn = document.getElementById('rescan');
const rescanStatus = document.getElementById('rescan-status');

rescanBtn.addEventListener('click', async () => {
  rescanBtn.disabled = true;
  rescanStatus.textContent = 'Scanning the shelves…';
  try {
    const res = await fetch('scan.php', { cache: 'no-store' });
    const text = (await res.text()).trim();
    if (!res.ok) throw new Error(text || ('HTTP ' + res.status));
    rescanStatus.textContent = text || 'Scan complete.';
    setTimeout(() => location.reload(), 700);
  } catch (err) {
    rescanStatus.textContent = (err instanceof Error && err.message)
      ? err.message
      : 'Scan failed — check the server logs.';
    rescanBtn.disabled = false;
  }
});

render();
</script>

</body>
</html>
