/**
 * PHARMACY ERP — PHASE 9: Universal Table System
 * FILE: js/universal_table.js
 *
 * Features:
 *   • Client-side search (instant filter)
 *   • Client-side sort (click column headers)
 *   • Client-side pagination (with per-page selector)
 *   • Export to Excel (SheetJS/XLSX)
 *   • Export to PDF  (jsPDF + AutoTable)
 *   • Print (clean print window)
 *
 * Dependencies (loaded via footer.php):
 *   xlsx@0.18.5, jspdf@2.5.1, jspdf-autotable@3.8.2
 *
 * Usage:
 *   UniversalTable.init('myTableId', {
 *     title       : 'Sales Report',   // used in exports/print
 *     pageSize    : 25,               // default rows per page (0 = all)
 *     excludeCols : [0, 5],           // column indexes to skip in exports
 *     sortable    : true,
 *     searchable  : true,
 *     exportExcel : true,
 *     exportPdf   : true,
 *     printable   : true,
 *   });
 */
(function (window) {
    'use strict';

    var instances = {};

    /* ── Helpers ───────────────────────────────────────────────────────────── */
    function esc(s) {
        return String(s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;')
            .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }
    function cellText(td) {
        return (td ? (td.textContent || td.innerText || '') : '').replace(/\s+/g, ' ').trim();
    }
    function slugify(s) {
        return String(s).toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_|_$/g, '');
    }
    function todayStr() {
        var d = new Date();
        return d.getFullYear() + '-' +
            String(d.getMonth() + 1).padStart(2, '0') + '-' +
            String(d.getDate()).padStart(2, '0');
    }

    /* ── Init ──────────────────────────────────────────────────────────────── */
    function init(tableId, opts) {
        if (instances[tableId]) return; // already initialised

        var table = document.getElementById(tableId);
        if (!table) {
            console.warn('[UniversalTable] #' + tableId + ' not found');
            return;
        }

        var cfg = Object.assign({
            title       : document.title || 'Table',
            pageSize    : 25,
            searchable  : true,
            sortable    : true,
            exportExcel : true,
            exportPdf   : true,
            printable   : true,
            pageSizes   : [10, 25, 50, 100, 0],
            excludeCols : []   // column indexes to skip in exports/sort
        }, opts || {});

        var state = {
            query   : '',
            sortCol : -1,
            sortDir : 'asc',
            page    : 1,
            perPage : cfg.pageSize,
            rows    : []
        };

        /* Snapshot all tbody rows */
        var tbody = table.querySelector('tbody');
        if (!tbody) return;
        Array.from(tbody.querySelectorAll('tr')).forEach(function (tr) {
            var cells = Array.from(tr.querySelectorAll('td')).map(cellText);
            state.rows.push({ el: tr, cells: cells, visible: true });
        });

        /* Build DOM: wrapper → toolbar + original table + pagination */
        var container = table.closest('.table-responsive') || table;
        var parent    = container.parentNode;

        var wrapper = document.createElement('div');
        wrapper.className = 'ut-wrapper';
        parent.insertBefore(wrapper, container);

        var toolbar = document.createElement('div');
        toolbar.className = 'ut-toolbar no-print';
        toolbar.innerHTML = buildToolbarHTML(cfg, state);
        wrapper.appendChild(toolbar);

        wrapper.appendChild(container);

        var paginationEl = document.createElement('div');
        paginationEl.className = 'ut-pagination-bar no-print';
        wrapper.appendChild(paginationEl);

        /* ── Wire: search ── */
        var searchInput = toolbar.querySelector('.ut-search-input');
        if (searchInput) {
            searchInput.addEventListener('input', function () {
                state.query = this.value.toLowerCase();
                state.page  = 1;
                render(state, table, tbody, paginationEl, cfg);
            });
        }

        /* ── Wire: per-page ── */
        var perPageSel = toolbar.querySelector('.ut-perpage-select');
        if (perPageSel) {
            perPageSel.addEventListener('change', function () {
                state.perPage = parseInt(this.value, 10);
                state.page    = 1;
                render(state, table, tbody, paginationEl, cfg);
            });
        }

        /* ── Wire: column sort ── */
        if (cfg.sortable) {
            var ths = Array.from(table.querySelectorAll('thead th'));
            ths.forEach(function (th, idx) {
                if (cfg.excludeCols.indexOf(idx) !== -1) return;
                th.style.cursor     = 'pointer';
                th.style.userSelect = 'none';
                th.title = 'Click to sort';
                th.addEventListener('click', function () {
                    if (state.sortCol === idx) {
                        state.sortDir = state.sortDir === 'asc' ? 'desc' : 'asc';
                    } else {
                        state.sortCol = idx;
                        state.sortDir = 'asc';
                    }
                    ths.forEach(function (h) {
                        h.classList.remove('ut-sort-asc', 'ut-sort-desc');
                    });
                    th.classList.add('ut-sort-' + state.sortDir);
                    state.page = 1;
                    render(state, table, tbody, paginationEl, cfg);
                });
            });
        }

        /* ── Wire: export / print buttons ── */
        var btnExcel = toolbar.querySelector('.ut-btn-excel');
        if (btnExcel) btnExcel.addEventListener('click', function () { doExportExcel(state, table, cfg); });

        var btnPdf = toolbar.querySelector('.ut-btn-pdf');
        if (btnPdf) btnPdf.addEventListener('click', function () { doExportPdf(state, table, cfg); });

        var btnPrint = toolbar.querySelector('.ut-btn-print');
        if (btnPrint) btnPrint.addEventListener('click', function () { doPrint(state, table, cfg); });

        /* Initial render */
        render(state, table, tbody, paginationEl, cfg);

        instances[tableId] = { state: state, cfg: cfg, table: table };
    }

    /* ── Toolbar HTML ──────────────────────────────────────────────────────── */
    function buildToolbarHTML(cfg, state) {
        var html = '<div class="ut-toolbar-row">';

        if (cfg.searchable) {
            html += '<div class="ut-search-wrap">'
                  + '<span class="ut-search-icon"><i class="fas fa-search"></i></span>'
                  + '<input type="text" class="ut-search-input" placeholder="Search…" autocomplete="off">'
                  + '</div>';
        }

        html += '<div class="ut-toolbar-actions">';

        if (cfg.exportExcel) {
            html += '<button type="button" class="ut-btn ut-btn-excel" title="Export to Excel">'
                  + '<i class="fas fa-file-excel"></i> Excel</button>';
        }
        if (cfg.exportPdf) {
            html += '<button type="button" class="ut-btn ut-btn-pdf" title="Export to PDF">'
                  + '<i class="fas fa-file-pdf"></i> PDF</button>';
        }
        if (cfg.printable) {
            html += '<button type="button" class="ut-btn ut-btn-print" title="Print table">'
                  + '<i class="fas fa-print"></i> Print</button>';
        }

        html += '<div class="ut-perpage-wrap"><label class="ut-perpage-label">Show</label>'
              + '<select class="ut-perpage-select">';
        cfg.pageSizes.forEach(function (n) {
            html += '<option value="' + n + '"' + (n === cfg.pageSize ? ' selected' : '') + '>'
                  + (n === 0 ? 'All' : n) + '</option>';
        });
        html += '</select><label class="ut-perpage-label">rows</label></div>';

        html += '</div></div>';
        html += '<div class="ut-info-row"><span class="ut-info-text"></span></div>';
        return html;
    }

    /* ── Render (filter + sort + paginate) ────────────────────────────────── */
    function render(state, table, tbody, paginationEl, cfg) {
        var q = state.query;

        /* 1. Filter */
        state.rows.forEach(function (row) {
            row.visible = !q || row.cells.some(function (c) {
                return c.toLowerCase().indexOf(q) !== -1;
            });
        });
        var visible = state.rows.filter(function (r) { return r.visible; });

        /* 2. Sort */
        if (state.sortCol >= 0) {
            var col = state.sortCol, dir = state.sortDir === 'asc' ? 1 : -1;
            visible.sort(function (a, b) {
                var av = a.cells[col] || '', bv = b.cells[col] || '';
                var an = parseFloat(av.replace(/[^0-9.\-]/g, '')),
                    bn = parseFloat(bv.replace(/[^0-9.\-]/g, ''));
                if (!isNaN(an) && !isNaN(bn)) return (an - bn) * dir;
                return av.localeCompare(bv) * dir;
            });
        }

        /* 3. Paginate */
        var perPage = state.perPage, total = visible.length;
        var pages   = perPage === 0 ? 1 : Math.ceil(total / perPage) || 1;
        if (state.page > pages) state.page = pages;
        var start    = perPage === 0 ? 0 : (state.page - 1) * perPage;
        var end      = perPage === 0 ? total : Math.min(start + perPage, total);
        var pageRows = visible.slice(start, end);

        /* 4. Render rows */
        state.rows.forEach(function (r) { r.el.style.display = 'none'; });
        pageRows.forEach(function (r) {
            r.el.style.display = '';
            tbody.appendChild(r.el); // re-order DOM for sort
        });

        /* 5. Empty state */
        var emptyRow = tbody.querySelector('.ut-empty-row');
        if (pageRows.length === 0) {
            if (!emptyRow) {
                var colCount = table.querySelectorAll('thead th').length;
                emptyRow = document.createElement('tr');
                emptyRow.className = 'ut-empty-row';
                emptyRow.innerHTML = '<td colspan="' + colCount + '" class="ut-empty-cell">'
                    + '<i class="fas fa-inbox fa-2x d-block mb-2 opacity-50"></i>'
                    + 'No records found</td>';
                tbody.appendChild(emptyRow);
            }
            emptyRow.style.display = '';
        } else if (emptyRow) {
            emptyRow.style.display = 'none';
        }

        /* 6. Info text */
        var wrapper  = table.closest('.ut-wrapper');
        var infoEl   = wrapper && wrapper.querySelector('.ut-info-text');
        if (infoEl) {
            if (total === 0) {
                infoEl.textContent = 'No records found';
            } else {
                infoEl.textContent = 'Showing ' + (start + 1) + ' – ' + end
                    + ' of ' + total + ' entries' + (q ? ' (filtered)' : '');
            }
        }

        /* 7. Pagination */
        renderPagination(paginationEl, state, pages, function (p) {
            state.page = p;
            render(state, table, tbody, paginationEl, cfg);
        });
    }

    /* ── Pagination ────────────────────────────────────────────────────────── */
    function renderPagination(el, state, pages, onClick) {
        if (pages <= 1) { el.innerHTML = ''; return; }
        var cur = state.page;
        var html = '<nav class="ut-pagination"><ul class="pagination pagination-sm mb-0">';

        html += '<li class="page-item' + (cur <= 1 ? ' disabled' : '') + '">'
              + '<a class="page-link" data-page="' + (cur - 1) + '" href="#">&laquo;</a></li>';

        var lo = Math.max(1, cur - 2), hi = Math.min(pages, lo + 4);
        lo = Math.max(1, hi - 4);

        if (lo > 1) {
            html += '<li class="page-item"><a class="page-link" data-page="1" href="#">1</a></li>';
            if (lo > 2) html += '<li class="page-item disabled"><a class="page-link" href="#">…</a></li>';
        }
        for (var i = lo; i <= hi; i++) {
            html += '<li class="page-item' + (i === cur ? ' active' : '') + '">'
                  + '<a class="page-link" data-page="' + i + '" href="#">' + i + '</a></li>';
        }
        if (hi < pages) {
            if (hi < pages - 1) html += '<li class="page-item disabled"><a class="page-link" href="#">…</a></li>';
            html += '<li class="page-item"><a class="page-link" data-page="' + pages + '" href="#">' + pages + '</a></li>';
        }
        html += '<li class="page-item' + (cur >= pages ? ' disabled' : '') + '">'
              + '<a class="page-link" data-page="' + (cur + 1) + '" href="#">&raquo;</a></li>';
        html += '</ul></nav>';

        el.innerHTML = html;
        Array.from(el.querySelectorAll('[data-page]')).forEach(function (a) {
            a.addEventListener('click', function (e) {
                e.preventDefault();
                var p = parseInt(this.dataset.page, 10);
                if (p >= 1 && p <= pages) onClick(p);
            });
        });
    }

    /* ── Export helpers ────────────────────────────────────────────────────── */
    function getHeaders(table, cfg) {
        return Array.from(table.querySelectorAll('thead th'))
            .filter(function (_, i) { return cfg.excludeCols.indexOf(i) === -1; })
            .map(cellText);
    }
    function getExportRows(state, cfg) {
        return state.rows
            .filter(function (r) { return r.visible; })
            .map(function (r) {
                return r.cells.filter(function (_, i) { return cfg.excludeCols.indexOf(i) === -1; });
            });
    }

    /* ── Export: Excel ─────────────────────────────────────────────────────── */
    function doExportExcel(state, table, cfg) {
        if (typeof XLSX === 'undefined') {
            Notify.error('SheetJS library not loaded. Please check your internet connection.');
            return;
        }
        var headers = getHeaders(table, cfg);
        var data    = getExportRows(state, cfg);
        var ws      = XLSX.utils.aoa_to_sheet([headers].concat(data));
        ws['!cols'] = headers.map(function (h) { return { wch: Math.max(12, h.length + 4) }; });
        var wb = XLSX.utils.book_new();
        XLSX.utils.book_append_sheet(wb, ws, 'Data');
        XLSX.writeFile(wb, slugify(cfg.title) + '_' + todayStr() + '.xlsx');
    }

    /* ── Export: PDF ───────────────────────────────────────────────────────── */
    function doExportPdf(state, table, cfg) {
        var JsPDF = (window.jspdf && window.jspdf.jsPDF) || window.jsPDF;
        if (!JsPDF) {
            Notify.error('jsPDF library not loaded. Please check your internet connection.');
            return;
        }
        var headers = getHeaders(table, cfg);
        var data    = getExportRows(state, cfg);

        var doc = new JsPDF({ orientation: 'landscape', unit: 'pt', format: 'a4' });
        doc.setFontSize(14);
        doc.setTextColor(40, 40, 40);
        doc.text(cfg.title, 40, 36);
        doc.setFontSize(9);
        doc.setTextColor(120, 120, 120);
        doc.text('Exported: ' + new Date().toLocaleString() + '   |   ' + data.length + ' records', 40, 52);

        doc.autoTable({
            head            : [headers],
            body            : data,
            startY          : 64,
            styles          : { fontSize: 8, cellPadding: 4 },
            headStyles      : { fillColor: [59, 130, 246], textColor: 255, fontStyle: 'bold' },
            alternateRowStyles : { fillColor: [245, 247, 250] },
            margin          : { left: 40, right: 40 }
        });

        doc.save(slugify(cfg.title) + '_' + todayStr() + '.pdf');
    }

    /* ── Print ─────────────────────────────────────────────────────────────── */
    function doPrint(state, table, cfg) {
        var headers = getHeaders(table, cfg);
        var rows    = getExportRows(state, cfg);

        var html = '<!DOCTYPE html><html><head><meta charset="utf-8">'
            + '<title>' + esc(cfg.title) + '</title>'
            + '<style>'
            + 'body{font-family:Arial,sans-serif;font-size:11pt;color:#111;margin:20px}'
            + 'h2{margin-bottom:4px;font-size:14pt;color:#1a1a2e}'
            + '.meta{color:#666;font-size:9pt;margin-bottom:12px}'
            + 'table{width:100%;border-collapse:collapse}'
            + 'th{background:#3b82f6;color:#fff;padding:7px 9px;text-align:left;font-size:10pt;font-weight:600}'
            + 'td{padding:5px 9px;border-bottom:1px solid #e5e7eb;font-size:10pt}'
            + 'tr:nth-child(even) td{background:#f8fafc}'
            + '@media print{@page{margin:15mm}body{margin:0}}'
            + '</style></head><body>'
            + '<h2>' + esc(cfg.title) + '</h2>'
            + '<div class="meta">Printed: ' + new Date().toLocaleString()
            + ' &nbsp;|&nbsp; ' + rows.length + ' records'
            + (state.query ? ' &nbsp;|&nbsp; Filter: "' + esc(state.query) + '"' : '')
            + '</div>'
            + '<table><thead><tr>'
            + headers.map(function (h) { return '<th>' + esc(h) + '</th>'; }).join('')
            + '</tr></thead><tbody>'
            + rows.map(function (r) {
                return '<tr>' + r.map(function (c) { return '<td>' + esc(c) + '</td>'; }).join('') + '</tr>';
              }).join('')
            + '</tbody></table>'
            + '<script>window.onload=function(){window.print();};<\/script>'
            + '</body></html>';

        var win = window.open('', '_blank', 'width=1100,height=750');
        if (!win) { Notify.warning('Please allow popups to use Print.'); return; }
        win.document.write(html);
        win.document.close();
    }

    /* ── Public API ────────────────────────────────────────────────────────── */
    window.UniversalTable = { init: init, instances: instances };

}(window));