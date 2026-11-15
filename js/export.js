/* =====================================================================
   Lost & Found — export.js
   Client-side export helpers for dashboard/admin tables: CSV, Excel (.xlsx)
   and PDF. Libraries (SheetJS, jsPDF) are lazy-loaded from CDN only when
   the corresponding export is actually used.
   ===================================================================== */

/** Extract a 2D array of [header, ...rows] from a table, skipping .no-export columns. */
function tableToMatrix(tableId) {
  const table = document.getElementById(tableId);
  if (!table) return [];

  const skipIndexes = [];
  const headerCells = Array.from(table.querySelectorAll('thead th'));
  headerCells.forEach((cell, idx) => {
    if (cell.classList.contains('no-export')) skipIndexes.push(idx);
  });

  const rows = Array.from(table.querySelectorAll('tr'));
  return rows.map(row => {
    const cells = Array.from(row.children);
    return cells
      .filter((_, idx) => !skipIndexes.includes(idx))
      .map(cell => cell.innerText.trim().replace(/\s+/g, ' '));
  });
}

/** ---------------------------- CSV export ---------------------------- */
function exportTableToCSV(tableId, filename = 'export.csv') {
  const matrix = tableToMatrix(tableId);
  if (matrix.length === 0) {
    alert('Nothing to export.');
    return;
  }
  const csv = matrix
    .map(row => row.map(cell => `"${String(cell).replace(/"/g, '""')}"`).join(','))
    .join('\r\n');
  downloadBlob(csv, filename, 'text/csv;charset=utf-8;');
}

/** ---------------------------- Excel (.xlsx) export -------------------- */
let sheetJsLoading = null;
function loadSheetJS() {
  if (window.XLSX) return Promise.resolve();
  if (sheetJsLoading) return sheetJsLoading;
  sheetJsLoading = new Promise((resolve, reject) => {
    const script = document.createElement('script');
    script.src = 'https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js';
    script.onload = resolve;
    script.onerror = () => reject(new Error('Failed to load Excel export library.'));
    document.head.appendChild(script);
  });
  return sheetJsLoading;
}

async function exportTableToExcel(tableId, filename = 'export.xlsx') {
  const matrix = tableToMatrix(tableId);
  if (matrix.length === 0) {
    alert('Nothing to export.');
    return;
  }
  try {
    await loadSheetJS();
    const ws = window.XLSX.utils.aoa_to_sheet(matrix);
    const wb = window.XLSX.utils.book_new();
    window.XLSX.utils.book_append_sheet(wb, ws, 'Data');
    window.XLSX.writeFile(wb, filename);
  } catch (err) {
    console.error(err);
    alert('Excel export failed to load. Falling back to CSV.');
    exportTableToCSV(tableId, filename.replace(/\.xlsx$/, '.csv'));
  }
}

/** ---------------------------- PDF export ------------------------------ */
let jsPdfLoading = null;
function loadJsPDF() {
  if (window.jspdf) return Promise.resolve();
  if (jsPdfLoading) return jsPdfLoading;
  jsPdfLoading = new Promise((resolve, reject) => {
    const core = document.createElement('script');
    core.src = 'https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js';
    core.onload = () => {
      const autotable = document.createElement('script');
      autotable.src = 'https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.31/jspdf.plugin.autotable.min.js';
      autotable.onload = resolve;
      autotable.onerror = () => reject(new Error('Failed to load PDF table plugin.'));
      document.head.appendChild(autotable);
    };
    core.onerror = () => reject(new Error('Failed to load PDF export library.'));
    document.head.appendChild(core);
  });
  return jsPdfLoading;
}

async function exportTableToPDF(tableId, title = 'Report') {
  const matrix = tableToMatrix(tableId);
  if (matrix.length === 0) {
    alert('Nothing to export.');
    return;
  }
  try {
    await loadJsPDF();
    const { jsPDF } = window.jspdf;
    const doc = new jsPDF({ orientation: 'landscape' });
    doc.setFontSize(14);
    doc.text(title, 14, 16);
    doc.autoTable({
      head: [matrix[0]],
      body: matrix.slice(1),
      startY: 22,
      styles: { fontSize: 8 },
      headStyles: { fillColor: [37, 99, 235] },
    });
    doc.save(title.replace(/\s+/g, '_').toLowerCase() + '.pdf');
  } catch (err) {
    console.error(err);
    alert('PDF export library could not be loaded. Please check your internet connection and try again, or use the CSV/Excel export instead.');
  }
}

/** ---------------------------- Shared download helper ------------------- */
function downloadBlob(content, filename, mimeType) {
  const blob = new Blob([content], { type: mimeType });
  const url = URL.createObjectURL(blob);
  const link = document.createElement('a');
  link.href = url;
  link.download = filename;
  document.body.appendChild(link);
  link.click();
  document.body.removeChild(link);
  URL.revokeObjectURL(url);
}
