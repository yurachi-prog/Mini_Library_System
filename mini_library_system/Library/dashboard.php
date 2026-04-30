<?php
session_start();
if (!isset($_SESSION['admin_id'])) {
    header("Location: ../Admin/auth.php");
    exit();
}

include("../Data/mini_lib_db.php");

$flashError   = '';
$flashSuccess = '';


if (isset($_POST['deleteBook'])) {
    $id = (int)$_POST['id'];


    $conn->query("
        ALTER TABLE borrow_records
        ADD COLUMN IF NOT EXISTS BookTitle_Snapshot VARCHAR(200) DEFAULT NULL
    ");

    /* Copy the title into every borrow record that references this book */
    $snap = $conn->prepare("
        UPDATE borrow_records br
        JOIN books b ON br.BookID = b.BookID
        SET br.BookTitle_Snapshot = b.Title
        WHERE br.BookID = ?
    ");
    $snap->bind_param("i", $id);
    $snap->execute();

    /* NULL out the FK so history rows survive */
    $nullify = $conn->prepare("UPDATE borrow_records SET BookID = NULL WHERE BookID = ?");
    $nullify->bind_param("i", $id);
    $nullify->execute();

    /* Now delete the book (FK is nullable so this succeeds) */
    $del = $conn->prepare("DELETE FROM books WHERE BookID = ?");
    $del->bind_param("i", $id);
    $del->execute();

}

/* ── ADD BOOK ── */
if (isset($_POST['addBook'])) {
    $title  = trim($_POST['title']);
    $author = trim($_POST['author']);
    $stmt   = $conn->prepare("INSERT INTO books (Title, Author, StatusID) VALUES (?, ?, 1)");
    $stmt->bind_param("ss", $title, $author);
    $stmt->execute();
    $flashSuccess = 'Book added successfully.';
}

/* ── ADD STUDENT ── */
if (isset($_POST['addStudent'])) {
    $fname  = trim($_POST['fname']);
    $lname  = trim($_POST['lname']);
    $email  = trim($_POST['email']);
    $course = trim($_POST['course']);
    $stmt   = $conn->prepare("INSERT INTO student (SFN, SLN, Email, Course) VALUES (?, ?, ?, ?)");
    $stmt->bind_param("ssss", $fname, $lname, $email, $course);
    $stmt->execute();
    $flashSuccess = 'Student added successfully.';
}

/* ── BORROW BOOK ────────────────────────────────────────────────────
   Check how many books the student is actively borrowing (not returned).
   If already at MaxBorrowLimit (default 3), block and warn.
   ------------------------------------------------------------------- */
if (isset($_POST['borrowBook'])) {
    $studentId = (int)$_POST['studentId'];
    $bookId    = (int)$_POST['bookId'];

    /* Get limit for this student */
    $limRow = $conn->query("SELECT MaxBorrowLimit FROM student WHERE StudentID = $studentId")->fetch_assoc();
    $limit  = $limRow ? (int)$limRow['MaxBorrowLimit'] : 3;

    /* Count active (unreturned) borrows */
    $activeRow = $conn->query("
        SELECT COUNT(*) AS n FROM borrow_records
        WHERE StudentID = $studentId AND Return_Date IS NULL
    ")->fetch_assoc();
    $active = (int)$activeRow['n'];

    if ($active >= $limit) {
        $flashError = "Cannot borrow: this student has already reached the maximum of $limit book(s). Please return a book first.";
    } else {
        $conn->query("UPDATE books SET StatusID = 2 WHERE BookID = $bookId");
        $conn->query("INSERT INTO borrow_records (StudentID, BookID, Borrow_Date) VALUES ($studentId, $bookId, CURDATE())");
        $flashSuccess = 'Book borrowed successfully.';
    }
}

/* ── RETURN BOOK ── */
if (isset($_POST['returnBook'])) {
    $bookId = (int)$_POST['bookId'];
    $conn->query("UPDATE books SET StatusID = 1 WHERE BookID = $bookId");
    $conn->query("UPDATE borrow_records SET Return_Date = CURDATE() WHERE BookID = $bookId AND Return_Date IS NULL");
    $flashSuccess = 'Book returned successfully.';
}

/* ── Stats ── */
$totalBooks    = $conn->query("SELECT COUNT(*) AS total FROM books")->fetch_assoc()['total'];
$borrowedBooks = $conn->query("SELECT COUNT(*) AS n FROM books WHERE StatusID = 2")->fetch_assoc()['n'];

/* ── Data queries ── */
$students             = $conn->query("SELECT * FROM student ORDER BY StudentID DESC");
$books                = $conn->query("
    SELECT b.*, bs.Status_Name
    FROM books b
    JOIN book_status bs ON b.StatusID = bs.StatusID
    ORDER BY b.BookID DESC
");
$availableBooks       = $conn->query("SELECT * FROM books WHERE StatusID = 1 ORDER BY Title");
$allStudentsForBorrow = $conn->query("SELECT * FROM student ORDER BY SFN, SLN");

/* Build active-borrow count per student for the JS warning */
$activeBorrowsResult = $conn->query("
    SELECT StudentID, COUNT(*) AS active
    FROM borrow_records
    WHERE Return_Date IS NULL
    GROUP BY StudentID
");
$activeBorrowsMap = [];
while ($row = $activeBorrowsResult->fetch_assoc()) {
    $activeBorrowsMap[$row['StudentID']] = (int)$row['active'];
}

/* Build per-student limit map */
$limitsResult = $conn->query("SELECT StudentID, MaxBorrowLimit FROM student");
$limitsMap = [];
while ($row = $limitsResult->fetch_assoc()) {
    $limitsMap[$row['StudentID']] = (int)$row['MaxBorrowLimit'];
}

/* Encode for JS */
$activeBorrowsJson = json_encode($activeBorrowsMap);
$limitsJson        = json_encode($limitsMap);

/* Borrow records — use BookTitle_Snapshot when BookID is NULL */
$hasSnapshot = false;
$colCheck = $conn->query("SHOW COLUMNS FROM borrow_records LIKE 'BookTitle_Snapshot'");
if ($colCheck && $colCheck->num_rows > 0) { $hasSnapshot = true; }

if ($hasSnapshot) {
    $records = $conn->query("
        SELECT br.*,
               s.SFN, s.SLN,
               COALESCE(b.Title, br.BookTitle_Snapshot, '[Deleted Book]') AS Title,
               br.BookID
        FROM borrow_records br
        JOIN student s ON br.StudentID = s.StudentID
        LEFT JOIN books b ON br.BookID = b.BookID
        ORDER BY br.BorrowID DESC
    ");
} else {
    $records = $conn->query("
        SELECT br.*, s.SFN, s.SLN, b.Title, b.BookID
        FROM borrow_records br
        JOIN student s ON br.StudentID = s.StudentID
        JOIN books b ON br.BookID = b.BookID
        ORDER BY br.BorrowID DESC
    ");
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Library Dashboard</title>
  <link rel="stylesheet" href="../style.css"/>
  <style>
    /* ── Borrow form: stack selects on their own row ── */
    .borrow-form {
      display: flex;
      flex-direction: column;
      gap: 10px;
    }
    .borrow-form .borrow-selects {
      display: flex;
      gap: 10px;
    }
    .borrow-form .borrow-selects select {
      flex: 1;
      min-width: 0;
      width: 0;
      padding: 10px 14px;
      font-size: 13px;
      font-family: var(--font-body);
      font-weight: 500;
      border: 1.5px solid var(--border);
      border-radius: var(--radius-md);
      background: var(--bg-input);
      color: var(--text);
      outline: none;
      cursor: pointer;
      appearance: none;
      -webkit-appearance: none;
      background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%238b97b0' stroke-width='2.5' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E");
      background-repeat: no-repeat;
      background-position: right 12px center;
      padding-right: 36px;
      transition: border-color 0.2s, box-shadow 0.2s, background 0.2s;
    }
    .borrow-form .borrow-selects select:hover {
      border-color: rgba(255,255,255,0.18);
    }
    .borrow-form .borrow-selects select:focus {
      border-color: var(--accent-3);
      box-shadow: 0 0 0 3px var(--accent-3-dim);
      background-color: var(--bg-hover);
    }
    .borrow-form .borrow-selects select option {
      background: var(--bg-card);
      color: var(--text);
    }
    .borrow-form button {
      align-self: flex-start;
    }

    /* ── Flash banners with auto-dismiss animation ── */
    .flash-banner {
      padding: 10px 14px;
      border-radius: 8px;
      font-size: 12.5px;
      font-weight: 600;
      margin: 12px 1.5rem 0;
      display: flex;
      align-items: center;
      gap: 8px;
      animation: flashIn 0.3s ease, flashOut 0.4s ease 0.9s forwards;
    }
    @keyframes flashIn {
      from { opacity: 0; transform: translateY(-6px); }
      to   { opacity: 1; transform: translateY(0); }
    }
    @keyframes flashOut {
      from { opacity: 1; transform: translateY(0); max-height: 60px; margin-top: 12px; }
      to   { opacity: 0; transform: translateY(-6px); max-height: 0; margin-top: 0; padding-top: 0; padding-bottom: 0; }
    }
    .flash-banner.error {
      background: rgba(248,113,113,0.12);
      color: #f87171;
      border: 1px solid rgba(248,113,113,0.25);
    }
    .flash-banner.success {
      background: rgba(52,211,153,0.10);
      color: #34d399;
      border: 1px solid rgba(52,211,153,0.22);
    }
    .flash-banner::before {
      font-size: 14px;
      flex-shrink: 0;
    }
    .flash-banner.error::before   { content: '⚠'; }
    .flash-banner.success::before { content: '✓'; }

    /* ── Warning when student is at limit ── */
    #borrow-limit-warning {
      display: none;
      padding: 8px 12px;
      border-radius: 7px;
      font-size: 12px;
      font-weight: 600;
      background: rgba(251,191,36,0.12);
      color: #fbbf24;
      border: 1px solid rgba(251,191,36,0.25);
    }
    #borrow-limit-warning::before { content: '⚠  '; }
  </style>
</head>
<body class="dashboard-body">

<!-- ══ HEADER ══════════════════════════════════════════════════════ -->
<header class="dash-header">
  <div class="dash-header-left">
    <h1>Library Management System</h1>
    <p>Welcome back, <em><?= htmlspecialchars($_SESSION['admin_name']) ?></em>
       &mdash; <?= htmlspecialchars($_SESSION['admin_role']) ?></p>
  </div>
  <div class="dash-header-right">
    <div class="stat-pill">Total Books <span class="count blue"><?= $totalBooks ?></span></div>
    <div class="stat-pill">Borrowed <span class="count red"><?= $borrowedBooks ?></span></div>
    <a href="../Admin/logout.php" class="logout-btn">Logout</a>
  </div>
</header>

<!-- ══ MAIN ═════════════════════════════════════════════════════════ -->
<main class="dash-main">

  <!-- ── STUDENT MANAGEMENT ── -->
  <section class="dash-card">
    <h2>Student Management</h2>
    <div class="card-body">
      <form method="POST" class="add-form">
        <input type="text"  name="fname"  placeholder="First Name" required/>
        <input type="text"  name="lname"  placeholder="Last Name"  required/>
        <input type="email" name="email"  placeholder="Email"      required/>
        <input type="text"  name="course" placeholder="Course (optional)"/>
        <button type="submit" name="addStudent" class="btn-add">Add Student</button>
      </form>
    </div>
    <div class="list-container">
      <?php if ($students->num_rows === 0): ?>
        <p class="empty-state">No students registered yet.</p>
      <?php else: ?>
        <?php while ($s = $students->fetch_assoc()): ?>
          <div class="list-item">
            <div class="list-item-info">
              <p><?= htmlspecialchars($s['SFN'] . ' ' . $s['SLN']) ?></p>
              <p><?= htmlspecialchars($s['Email']) ?>
                <?= $s['Course'] ? ' &bull; ' . htmlspecialchars($s['Course']) : '' ?>
              </p>
            </div>
            <span class="list-item-meta">Limit: <?= (int)$s['MaxBorrowLimit'] ?></span>
          </div>
        <?php endwhile; ?>
      <?php endif; ?>
    </div>
  </section>

  <!-- ── BOOK MANAGEMENT ── -->
  <section class="dash-card">
    <h2>Book Management</h2>
    <div class="card-body">
      <form method="POST" class="add-form">
        <input type="text" name="title"  placeholder="Book Title" required/>
        <input type="text" name="author" placeholder="Author"     required/>
        <button type="submit" name="addBook" class="btn-add green">Add Book</button>
      </form>
    </div>
    <div class="list-container">
      <?php if ($books->num_rows === 0): ?>
        <p class="empty-state">No books in the library yet.</p>
      <?php else: ?>
        <?php while ($b = $books->fetch_assoc()): ?>
          <div class="list-item">
            <div class="list-item-info">
              <p><?= htmlspecialchars($b['Title']) ?></p>
              <p><?= htmlspecialchars($b['Author']) ?></p>
            </div>
            <div style="display:flex;align-items:center;gap:8px;">
              <span class="status-badge <?= $b['StatusID'] == 1 ? 'status-available' : 'status-borrowed' ?>">
                <?= htmlspecialchars($b['Status_Name']) ?>
              </span>
              <?php if ($b['StatusID'] == 1): ?>
                <form method="POST" style="margin:0;"
                      onsubmit="return confirm('Delete this book? Borrow history will be preserved.');">
                  <input type="hidden" name="id" value="<?= $b['BookID'] ?>"/>
                  <button type="submit" name="deleteBook" class="btn-delete">Delete</button>
                </form>
              <?php endif; ?>
            </div>
          </div>
        <?php endwhile; ?>
      <?php endif; ?>
    </div>
  </section>

  <!-- ── BORROWING SYSTEM ── -->
  <section class="dash-card">
    <h2>Borrowing System</h2>

    <?php if ($flashError): ?>
      <div class="flash-banner error"><?= htmlspecialchars($flashError) ?></div>
    <?php endif; ?>
    <?php if ($flashSuccess): ?>
      <div class="flash-banner success"><?= htmlspecialchars($flashSuccess) ?></div>
    <?php endif; ?>

    <div class="card-body">
      <form method="POST" class="borrow-form" onsubmit="return checkBorrowLimit()">

        <div class="borrow-selects">
          <select name="studentId" id="studentSelect" required onchange="updateWarning()">
            <option value="">Select Student</option>
            <?php
              $allStudentsForBorrow->data_seek(0);
              while ($s = $allStudentsForBorrow->fetch_assoc()):
                $sid    = $s['StudentID'];
                $active = $activeBorrowsMap[$sid] ?? 0;
                $limit  = $limitsMap[$sid]         ?? 3;
                $atLimit = ($active >= $limit);
            ?>
              <option value="<?= $sid ?>"
                <?= $atLimit ? 'data-at-limit="1"' : '' ?>>
                <?= htmlspecialchars($s['SFN'] . ' ' . $s['SLN']) ?>
                <?= $atLimit ? ' (limit reached)' : '' ?>
              </option>
            <?php endwhile; ?>
          </select>

          <select name="bookId" required>
            <option value="">Select Book</option>
            <?php while ($ab = $availableBooks->fetch_assoc()): ?>
              <option value="<?= $ab['BookID'] ?>"><?= htmlspecialchars($ab['Title']) ?></option>
            <?php endwhile; ?>
          </select>
        </div>

        <div id="borrow-limit-warning">
          This student has reached their maximum borrow limit. Please return a book first.
        </div>

        <button type="submit" name="borrowBook" class="btn-add purple">Borrow</button>
      </form>
    </div>

    <div class="list-container">
      <?php if ($records->num_rows === 0): ?>
        <p class="empty-state">No borrow records yet.</p>
      <?php else: ?>
        <?php while ($r = $records->fetch_assoc()): ?>
          <div class="list-item">
            <div class="list-item-info">
              <p><?= htmlspecialchars($r['SFN'] . ' ' . $r['SLN']) ?>
                 &mdash; <?= htmlspecialchars($r['Title']) ?></p>
              <p>Borrowed: <?= htmlspecialchars($r['Borrow_Date']) ?>
                <?= $r['Return_Date'] ? ' &bull; Returned: ' . htmlspecialchars($r['Return_Date']) : '' ?>
              </p>
            </div>
            <?php if (!$r['Return_Date'] && $r['BookID']): ?>
              <form method="POST" style="margin:0;">
                <input type="hidden" name="bookId" value="<?= $r['BookID'] ?>"/>
                <button type="submit" name="returnBook" class="btn-return">Return</button>
              </form>
            <?php else: ?>
              <span class="status-badge status-available">Returned</span>
            <?php endif; ?>
          </div>
        <?php endwhile; ?>
      <?php endif; ?>
    </div>
  </section>

</main>

<script>
const activeBorrows = <?= $activeBorrowsJson ?>;
const borrowLimits  = <?= $limitsJson ?>;

function updateWarning() {
  const sel     = document.getElementById('studentSelect');
  const sid     = sel.value;
  const warning = document.getElementById('borrow-limit-warning');
  if (!sid) { warning.style.display = 'none'; return; }
  const active = activeBorrows[sid] || 0;
  const limit  = borrowLimits[sid]  || 3;
  warning.style.display = (active >= limit) ? 'block' : 'none';
}

function checkBorrowLimit() {
  const sel    = document.getElementById('studentSelect');
  const sid    = sel.value;
  if (!sid) return true;
  const active = activeBorrows[sid] || 0;
  const limit  = borrowLimits[sid]  || 3;
  if (active >= limit) {
    document.getElementById('borrow-limit-warning').style.display = 'block';
    return false; /* block submit */
  }
  return true;
}
</script>

</body>
</html>