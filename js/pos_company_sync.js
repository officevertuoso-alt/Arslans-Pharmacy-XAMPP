/**
 * FILE: assets/js/pos_company_sync.js
 * ════════════════════════════════════════════════════════════════
 * POS COMPANY SYNC — Include this in sales/pos.php AFTER page_head.php
 * Patches buildLocalThermalPreview to use window.PHARMACY_CONFIG
 * Also patches confirmInvoice footer text.
 * ════════════════════════════════════════════════════════════════
 */

// Override buildLocalThermalPreview to use PHARMACY_CONFIG
window._origBuildLocalThermalPreview = window.buildLocalThermalPreview;

window.buildLocalThermalPreview = function(custName, custPhone, method, oPct, oTax, paid, dueDate) {
    var cfg = window.PHARMACY_CONFIG || {};

    // Company info from config (never hardcoded)
    var companyName  = cfg.name       || 'PHARMACY';
    var tagline      = cfg.tagline    || '';
    var address      = cfg.address    || '';
    var phone        = cfg.phone      || '';
    var email        = cfg.email      || '';
    var gstNo        = cfg.gst_no     || '';
    var logoUrl      = cfg.logo_url   || '';
    var showLogo     = cfg.show_logo;
    var showTagline  = cfg.show_tagline;
    var footerText   = cfg.footer_text  || 'Thank you! Visit Again.';
    var invTerms     = cfg.invoice_terms || '';
    var currency     = cfg.currency     || 'Rs';
    var invPrefix    = cfg.invoice_prefix || 'INV';

    var now  = new Date();
    var dd   = String(now.getDate()).padStart(2,'0');
    var mm   = String(now.getMonth()+1).padStart(2,'0');
    var yyyy = now.getFullYear();
    var hh   = now.getHours();
    var min  = String(now.getMinutes()).padStart(2,'0');
    var ampm = hh >= 12 ? 'pm' : 'am';
    hh = hh % 12 || 12;
    var dateStr = dd+'/'+mm+'/'+yyyy+' '+hh+':'+min+' '+ampm;
    var tmpInv  = invPrefix+'-'+yyyy+mm+dd+'-'+Math.floor(Math.random()*9000+1000);
    var methodLabel = {'cash':'Cash','card':'Card','online':'Online','credit':'Credit'}[method] || method;

    function shorten(s, max) {
        if (!s) return '';
        s = String(s);
        return s.length > max ? s.substring(0, max-1)+'\u2026' : s;
    }

    // esc function (reuse global if available)
    var escFn = (typeof esc === 'function') ? esc : function(t) {
        return t ? String(t).replace(/[&<>"']/g, function(m){
            return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m];
        }) : '';
    };

    // ── Merge cart state into grouped items ──────────────────
    var mergedMap = {}, mergedOrder = [];
    if (typeof cartState !== 'undefined') {
        for (var i = 0; i < cartState.length; i++) {
            var ci  = cartState[i];
            var pid = ci.product_id;
            var md  = ci.sell_mode || 'piece';
            var upp = parseInt(ci.units_per_pack) || 1;
            var pcs = parseInt(ci.qty_pieces) || (ci.quantity * (md==='pack' ? upp : 1));
            if (!mergedMap[pid]) {
                mergedMap[pid] = {
                    product_id:    pid,
                    product_name:  ci.product_name  || '',
                    isMed:         parseInt(ci.category_id) === 1,
                    dosage_form:   ci.dosage_form   || '',
                    strength:      ci.strength      || '',
                    generic_name:  ci.generic_name  || '',
                    brand_name:    ci.brand_name    || '',
                    size_value:    ci.size_value    || '',
                    unit_type:     ci.unit_type     || 'Pcs',
                    upp:           upp,
                    item_discount: parseFloat(ci.item_discount) || 0,
                    batches:       ci.batches || [],
                    totalPieces:   pcs,
                    price:         parseFloat(ci.price) || 0
                };
                mergedOrder.push(pid);
            } else {
                mergedMap[pid].totalPieces += pcs;
                if (ci.batches && ci.batches.length > mergedMap[pid].batches.length)
                    mergedMap[pid].batches = ci.batches;
            }
        }
    }

    var invoiceRows = [];
    var subtotal    = 0;

    for (var m = 0; m < mergedOrder.length; m++) {
        var item      = mergedMap[mergedOrder[m]];
        var remaining = item.totalPieces;
        var batches   = item.batches;
        var itemDisc  = item.item_discount;
        var batchSerial = 0;
        var itemRows    = [];

        if (batches.length > 0) {
            for (var b = 0; b < batches.length && remaining > 0; b++) {
                var bat    = batches[b];
                var takePC = Math.min(remaining, parseInt(bat.remaining_quantity)||0);
                if (takePC <= 0) continue;
                batchSerial++;
                var bPrice  = parseFloat(bat.selling_price) || item.price;
                var lb      = takePC * bPrice;
                var ld      = lb * itemDisc / 100;
                var lT      = lb - ld;
                subtotal   += lT;
                itemRows.push({ batchSerial:batchSerial, qty:takePC, price:bPrice, lineTotal:lT, item:item, isFirst:batchSerial===1 });
                remaining  -= takePC;
            }
        }
        if (itemRows.length === 0 || remaining > 0) {
            var fp  = parseFloat((batches[0]||{}).selling_price) || item.price || 0;
            var fq  = itemRows.length === 0 ? item.totalPieces : remaining;
            var flb = fq * fp, fld = flb * itemDisc / 100, flT = flb - fld;
            subtotal += flT;
            batchSerial++;
            itemRows.push({ batchSerial:batchSerial, qty:fq, price:fp, lineTotal:flT, item:item, isFirst:batchSerial===1 });
        }
        for (var ir = 0; ir < itemRows.length; ir++) invoiceRows.push(itemRows[ir]);
    }

    var oDiscA  = subtotal * oPct / 100;
    var afterOD = subtotal - oDiscA;
    var grand   = afterOD + (afterOD * oTax / 100);
    var bal     = grand - paid;

    // ── Build HTML ────────────────────────────────────────────
    var W = 'font-family:\'Courier New\',Courier,monospace;font-size:11px;width:320px;margin:0 auto;color:#111;background:#fff;line-height:1.55;';
    var D = '<div style="'+W+'">';

    // HEADER — uses PHARMACY_CONFIG
    D += '<div style="text-align:center;border-bottom:2px dashed #333;padding-bottom:6px;margin-bottom:6px;">';

    // Logo (if enabled and URL available)
    if (showLogo && logoUrl) {
        D += '<div style="margin-bottom:5px;"><img src="'+escFn(logoUrl)+'" alt="'+escFn(companyName)+'"'
            + ' style="max-height:55px;max-width:160px;object-fit:contain;" onerror="this.style.display=\'none\'"></div>';
    }

    D += '<div style="font-size:16px;font-weight:900;letter-spacing:2px;text-transform:uppercase;">'+escFn(companyName)+'</div>';

    if (showTagline && tagline) {
        D += '<div style="font-size:9.5px;color:#555;font-style:italic;">'+escFn(tagline)+'</div>';
    }
    if (address) D += '<div style="font-size:9.5px;color:#555;">'+escFn(address)+'</div>';
    if (phone)   D += '<div style="font-size:9.5px;color:#555;">Tel: '+escFn(phone)+'</div>';
    if (email)   D += '<div style="font-size:9.5px;color:#555;">'+escFn(email)+'</div>';
    if (gstNo)   D += '<div style="font-size:9.5px;color:#555;">GST: '+escFn(gstNo)+'</div>';

    D += '<div style="font-size:9px;color:#888;font-style:italic;">** Computer Generated Invoice **</div>';
    D += '</div>'; // end header

    // META
    D += '<div style="font-size:9.5px;line-height:1.65;margin-bottom:7px;border-bottom:1px dashed #ccc;padding-bottom:6px;">';
    D += '<div><b>Invoice#:</b> <b>'+escFn(tmpInv)+'</b></div>';
    D += '<div><b>Date:</b> '+dateStr+'</div>';
    D += '<div><b>Customer:</b> <b>'+escFn(custName||'Walk-in Customer')+'</b></div>';
    if (custPhone) D += '<div><b>Phone:</b> '+escFn(custPhone)+'</div>';
    if (method==='credit' && dueDate) {
        D += '<div style="color:#c0392b;font-weight:700;"><b>Due Date:</b> '+escFn(dueDate)+'</div>';
    }
    D += '</div>'; // end meta

    // ITEMS TABLE
    D += '<table style="width:100%;border-collapse:collapse;font-size:9px;margin-bottom:4px;">'
        +'<colgroup><col style="width:14px;"><col><col style="width:26px;"><col style="width:26px;"><col style="width:44px;"><col style="width:46px;"></colgroup>'
        +'<thead><tr style="border-top:1.5px solid #111;border-bottom:1.5px solid #111;">'
        +'<th style="text-align:left;padding:3px 1px;font-size:8.5px;">#</th>'
        +'<th style="text-align:left;padding:3px 2px;font-size:8.5px;">Item / Details</th>'
        +'<th style="text-align:center;padding:3px 1px;font-size:8.5px;">Btch</th>'
        +'<th style="text-align:center;padding:3px 1px;font-size:8.5px;">Qty</th>'
        +'<th style="text-align:right;padding:3px 1px;font-size:8.5px;">Price</th>'
        +'<th style="text-align:right;padding:3px 2px;font-size:8.5px;">Total</th>'
        +'</thead><tbody>';

    var srNo = 0;
    for (var r = 0; r < invoiceRows.length; r++) {
        var row    = invoiceRows[r];
        var itm    = row.item;
        var upp2   = parseInt(itm.upp) || 1;
        var isCont = !row.isFirst;
        if (row.isFirst) srNo++;

        var prodCell = '';
        if (row.isFirst) {
            var line1 = '';
            if (itm.isMed) {
                var pname1 = escFn(shorten(itm.product_name, 18));
                var form1  = itm.dosage_form ? ' ('+escFn(itm.dosage_form)+')' : '';
                var str1   = itm.strength    ? ' '+escFn(itm.strength)         : '';
                line1 = '<b>'+pname1+form1+str1+'</b>';
            } else {
                var pname2 = escFn(shorten(itm.product_name, 22));
                var sz1    = itm.size_value ? ' ('+escFn(itm.size_value)+')' : '';
                line1 = '<b>'+pname2+sz1+'</b>';
            }
            prodCell = line1;

            var line2 = '';
            if (itm.isMed && itm.generic_name) {
                line2 = escFn(shorten(itm.generic_name, 22));
                if (upp2 > 1) line2 += ' <span style="color:#888;">(\xd7'+upp2+')</span>';
            } else if (!itm.isMed && itm.brand_name) {
                line2 = escFn(shorten(itm.brand_name, 22));
                if (upp2 > 1) line2 += ' <span style="color:#888;">(\xd7'+upp2+')</span>';
            } else if (upp2 > 1) {
                line2 = '<span style="color:#888;">(\xd7'+upp2+')</span>';
            }
            if (line2) prodCell += '<br><span style="font-size:8.5px;color:#444;">'+line2+'</span>';

            if (itm.item_discount > 0) {
                prodCell += '<br><span style="font-size:8px;color:#c0392b;">Disc '+itm.item_discount.toFixed(1)+'%</span>';
            }
        } else {
            prodCell = '<span style="color:#7c3aed;font-size:9px;">\u21b3 '+escFn(shorten(itm.product_name, 22))+'</span>';
        }

        var rowBg = isCont ? 'background:#f5f0ff;' : '';
        var rowBd = 'border-bottom:1px dotted #ccc;';

        D += '<tr style="'+rowBg+rowBd+'">'
            +'<td style="vertical-align:top;padding:3px 1px;color:#555;font-weight:bold;">'+(row.isFirst?srNo:'')+'</td>'
            +'<td style="vertical-align:top;padding:3px 2px;line-height:1.35;word-break:break-word;">'+prodCell+'</td>'
            +'<td style="text-align:center;vertical-align:top;padding:3px 1px;font-weight:bold;font-size:9.5px;">'+row.batchSerial+'</td>'
            +'<td style="text-align:center;vertical-align:top;padding:3px 1px;font-weight:bold;font-size:9.5px;">'+row.qty+'</td>'
            +'<td style="text-align:right;vertical-align:top;padding:3px 1px;">'+row.price.toFixed(2)+'</td>'
            +'<td style="text-align:right;vertical-align:top;padding:3px 2px;font-weight:bold;">'+row.lineTotal.toFixed(2)+'</td>'
        +'</tr>';
    }
    D += '</tbody></table>';

    // TOTALS
    D += '<div style="border-top:1.5px dashed #333;margin-top:4px;padding-top:5px;font-size:9.5px;line-height:1.75;">';

    if (invoiceRows.length > 1 || oPct > 0 || oTax > 0) {
        D += '<div style="display:flex;justify-content:space-between;">'
            +'<span>Sub-Total:</span><b>'+currency+' '+subtotal.toFixed(2)+'</b></div>';
    }

    if (oPct > 0) {
        D += '<div style="display:flex;justify-content:space-between;color:#c0392b;">'
            +'<span>Discount ('+oPct.toFixed(1)+'%)</span>'
            +'<span>- '+currency+' '+oDiscA.toFixed(2)+'</span></div>';
    }

    if (oTax > 0) {
        D += '<div style="display:flex;justify-content:space-between;color:#27ae60;">'
            +'<span>Tax ('+oTax.toFixed(1)+'%)</span>'
            +'<span>+ '+currency+' '+(afterOD * oTax / 100).toFixed(2)+'</span></div>';
    }

    D += '<div style="display:flex;justify-content:space-between;font-weight:900;font-size:12.5px;'
        +'border-top:1.5px solid #111;margin-top:3px;padding-top:4px;">'
        +'<span>GRAND TOTAL:</span><span>'+currency+' '+grand.toFixed(2)+'</span></div>';

    D += '<div style="display:flex;justify-content:space-between;font-size:9.5px;margin-top:2px;">'
        +'<span>Payment: '+methodLabel+'</span>'
        +'<span>Paid: '+currency+' '+paid.toFixed(2)+'</span></div>';

    if (Math.abs(bal) > 0.005) {
        var bCol = bal > 0 ? '#c0392b' : '#27ae60';
        var bLbl = bal > 0 ? 'Balance Due:' : 'Change:';
        D += '<div style="display:flex;justify-content:space-between;font-weight:800;color:'+bCol+';font-size:10.5px;">'
            +'<span>'+bLbl+'</span><span>'+currency+' '+Math.abs(bal).toFixed(2)+'</span></div>';
    }

    if (method === 'credit' && dueDate) {
        D += '<div style="color:#c0392b;font-size:10px;font-weight:800;margin-top:3px;padding:4px 6px;'
            +'background:#fdecea;border-radius:4px;">'
            +'⚠ CREDIT SALE — Due: '+escFn(dueDate)+'</div>';
    }
    D += '</div>'; // end totals

    // FOOTER — uses settings
    D += '<div style="border-top:2px dashed #333;margin-top:6px;padding-top:6px;text-align:center;font-size:9px;color:#666;">';
    D += '<div style="font-weight:600;font-size:10px;color:#444;">'+escFn(footerText)+'</div>';
    if (invTerms) {
        D += '<div style="font-size:8.5px;color:#888;margin-top:2px;font-style:italic;">'+escFn(invTerms)+'</div>';
    }
    D += '<div style="color:#aaa;font-size:9px;">*** Powered by Pharmacy POS ***</div>';
    D += '</div>';

    D += '</div>'; // close outer wrapper

    return { html: D, invoice_no: tmpInv };
};