// ========== Constants ==========
const requiredStartTime = new Date();
requiredStartTime.setHours(8, 0, 0);
const gracePeriodMinutes = 5;

const students = [
  { name: 'Jonathan Damasco', id: 'ST-2025-001' },
  { name: 'Ryan Johnson', id: 'ST-2025-002' },
  { name: 'Sophia Lee', id: 'ST-2025-003' }
];

const tableBody = document.getElementById('attendanceTableBody');
const activityList = document.getElementById('activityList');

// ========== Data ==========
let storedData = JSON.parse(localStorage.getItem('attendanceData')) || {};
let storedActivity = JSON.parse(localStorage.getItem('activityLog')) || [];

// ========== Utils ==========
function formatTime(dateObj) {
  return dateObj.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', hour12: true });
}

function saveToStorage() {
  localStorage.setItem('attendanceData', JSON.stringify(storedData));
  localStorage.setItem('activityLog', JSON.stringify(storedActivity));
}

function logActivity(message, type = 'default') {
  const iconMap = {
    user: '👤',
    late: '⏰',
    sick: '❌',
    done: '✅',
    report: '📄',
    default: '🔹'
  };
  const li = document.createElement('li');
  li.innerHTML = `<span class="icon ${type}">${iconMap[type] || iconMap.default}</span> ${message}`;
  activityList.prepend(li);
  storedActivity.unshift({ message, type });
  if (storedActivity.length > 10) storedActivity.pop();
  saveToStorage();
}

// ========== Restore ==========
storedActivity.forEach(a => logActivity(a.message, a.type));

// ========== Render Table ==========
students.forEach(student => {
  const data = storedData[student.id] || {};
  const row = document.createElement('tr');
  row.dataset.id = student.id;

  row.innerHTML = `
    <td><strong>${student.name}</strong><br/><small>ID: ${student.id}</small></td>
    <td class="time-in">${data.timeIn || '--:--'}</td>
    <td class="time-out">${data.timeOut || '--:--'}</td>
    <td class="status ${data.statusClass || ''}">${data.status || 'Not Marked'}</td>
    <td class="hours">${data.hours || '0.00'}</td>
    <td><input type="text" placeholder="Notes" value="${data.notes || ''}" /></td>
    <td>
      <button class="time-in-btn" ${data.timeIn ? 'disabled' : ''}>Time In</button>
      <button class="time-out-btn" ${data.timeOut ? 'disabled' : ''}>Time Out</button>
    </td>
  `;
  tableBody.appendChild(row);
});

// ========== Time In ==========
document.querySelectorAll('.time-in-btn').forEach(btn => {
  btn.addEventListener('click', () => {
    const row = btn.closest('tr');
    const id = row.dataset.id;
    const name = row.querySelector('strong').innerText;
    const now = new Date();
    const displayTime = formatTime(now);

    const graceTime = new Date(requiredStartTime);
    graceTime.setMinutes(gracePeriodMinutes + graceTime.getMinutes());
    const isLate = now > graceTime;

    const status = isLate ? 'Late' : 'Present';
    const statusClass = isLate ? 'late' : 'present';

    row.querySelector('.time-in').innerText = displayTime;
    const statusCell = row.querySelector('.status');
    statusCell.innerText = status;
    statusCell.className = `status ${statusClass}`;
    btn.disabled = true;

    storedData[id] = storedData[id] || {};
    storedData[id].timeIn = displayTime;
    storedData[id].status = status;
    storedData[id].statusClass = statusClass;
    storedData[id].timeInRaw = now.toISOString();
    saveToStorage();

    logActivity(`${name} checked in at ${displayTime} (${status})`, isLate ? 'late' : 'user');
  });
});

// ========== Time Out ==========
document.querySelectorAll('.time-out-btn').forEach(btn => {
  btn.addEventListener('click', () => {
    const row = btn.closest('tr');
    const id = row.dataset.id;
    const name = row.querySelector('strong').innerText;
    const now = new Date();
    const displayTime = formatTime(now);

    if (!storedData[id]?.timeInRaw) return alert("Please Time In first.");

    const timeIn = new Date(storedData[id].timeInRaw);
    const hours = ((now - timeIn) / (1000 * 60 * 60)).toFixed(2);

    row.querySelector('.time-out').innerText = displayTime;
    row.querySelector('.hours').innerText = hours;
    btn.disabled = true;

    storedData[id].timeOut = displayTime;
    storedData[id].hours = hours;
    saveToStorage();

    logActivity(`${name} checked out at ${displayTime} (${hours} hrs)`, 'done');
  });
});

// ========== Export to CSV ==========
document.getElementById('exportBtn')?.addEventListener('click', () => {
  let csv = "Name,ID,Time In,Time Out,Status,Hours,Notes\n";
  document.querySelectorAll('#attendanceTableBody tr').forEach(row => {
    const id = row.dataset.id;
    const cells = row.querySelectorAll('td');
    const notes = cells[5].querySelector('input').value;
    csv += [
      row.querySelector('strong').innerText,
      id,
      cells[1].innerText,
      cells[2].innerText,
      cells[3].innerText,
      cells[4].innerText,
      `"${notes}"`
    ].join(",") + "\n";
  });

  const blob = new Blob([csv], { type: "text/csv" });
  const link = document.createElement("a");
  link.href = URL.createObjectURL(blob);
  link.download = `Attendance-${new Date().toISOString().split('T')[0]}.csv`;
  link.click();

  logActivity("You exported today's attendance report", 'report');
});

// ========== Mark All Present ==========
document.getElementById('markAllBtn')?.addEventListener('click', () => {
  document.querySelectorAll('.status').forEach(cell => {
    cell.innerText = 'Present';
    cell.className = 'status present';
  });
  logActivity("All students marked present", 'user');
});

// ========== Clear All Data ==========
document.getElementById('clearBtn')?.addEventListener('click', () => {
  if (confirm("Are you sure you want to clear all attendance data?")) {
    localStorage.removeItem('attendanceData');
    localStorage.removeItem('activityLog');
    location.reload();
  }
});

// ========== Weekly Line Chart ==========
new Chart(document.getElementById('weeklyLineChart'), {
  type: 'line',
  data: {
    labels: ['Mon', 'Tue', 'Wed', 'Thu', 'Fri'],
    datasets: [
      { label: 'Present', data: [9, 10, 8, 9, 10], borderColor: '#2196f3', backgroundColor: 'rgba(33,150,243,0.1)', fill: true, tension: 0.4 },
      { label: 'Late', data: [1, 1, 2, 1, 0], borderColor: '#fbc02d', backgroundColor: 'rgba(251,192,45,0.1)', fill: true, tension: 0.4 },
      { label: 'Absent', data: [2, 1, 2, 1, 2], borderColor: '#e53935', backgroundColor: 'rgba(229,57,53,0.1)', fill: true, tension: 0.4 }
    ]
  },
  options: { responsive: true, plugins: { legend: { position: 'bottom' } }, scales: { y: { beginAtZero: true } } }
});

// ========== Donut Chart ==========
new Chart(document.getElementById('attendanceDonutChart'), {
  type: 'doughnut',
  data: {
    labels: ['Present', 'Late', 'Absent'],
    datasets: [{ data: [80, 10, 10], backgroundColor: ['#42a5f5', '#ffca28', '#ef5350'] }]
  },
  options: { responsive: true, cutout: '70%', plugins: { legend: { position: 'bottom' } } }
});