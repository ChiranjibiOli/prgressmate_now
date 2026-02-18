<?php
session_start();
require_once '../includes/db_connection.php';
require_once '../includes/functions.php';
require_once '../api/_helpers.php';

checkAuth('admin');
$csrf = csrfToken();
?>
<!doctype html>
<html>

<head>
  <meta charset="utf-8">
  <title>Progress Requests</title>
  <style>
    body {
      font-family: Arial;
      padding: 20px
    }

    .card {
      border: 1px solid #ddd;
      padding: 12px;
      border-radius: 8px;
      margin-bottom: 10px
    }

    .row {
      display: flex;
      gap: 8px;
      align-items: center;
      flex-wrap: wrap
    }

    button {
      padding: 8px 12px;
      cursor: pointer
    }

    textarea {
      width: 100%;
      min-height: 60px
    }
  </style>
</head>

<body>

  <h2>Pending Progress Requests</h2>

  <div id="list"></div>

  <script>
    const CSRF = <?php echo json_encode($csrf); ?>;

    async function fetchList() {
      const res = await fetch('../api/admin/progress_list.php');
      const data = await res.json();
      if (!data.success) {
        alert(data.error || 'Failed');
        return;
      }

      const list = document.getElementById('list');
      list.innerHTML = '';

      if (!data.items || data.items.length === 0) {
        list.innerHTML = '<p>No pending requests ✅</p>';
        return;
      }

      data.items.forEach(item => {
        const div = document.createElement('div');
        div.className = 'card';
        div.innerHTML = `
      <div><b>${item.student_name}</b> (${item.student_email})</div>
      <div><b>Goal:</b> ${item.goal_title}</div>
      <div><b>Progress:</b> +${item.progress_value}</div>
      <div><b>Notes:</b> ${item.notes || ''}</div>
      <div class="row" style="margin-top:10px">
        <button onclick="decide(${item.id}, 'approve')">Approve</button>
        <button onclick="decide(${item.id}, 'reject')">Reject</button>
      </div>
      <div style="margin-top:8px">
        <small>Admin note (optional):</small>
        <textarea id="note_${item.id}" placeholder="Reason / message to student..."></textarea>
      </div>
    `;
        list.appendChild(div);
      });
    }

    async function decide(progressId, action) {
      const note = document.getElementById('note_' + progressId)?.value || '';
      const fd = new FormData();
      fd.append('csrf_token', CSRF);
      fd.append('progress_id', progressId);
      fd.append('action', action);
      fd.append('admin_note', note);

      const res = await fetch('../api/admin/progress_decide.php', {
        method: 'POST',
        body: fd
      });
      const data = await res.json();
      if (!data.success) {
        alert(data.error || 'Failed');
        return;
      }

      alert(data.message);
      fetchList(); // refresh
    }

    fetchList();
  </script>
</body>

</html>