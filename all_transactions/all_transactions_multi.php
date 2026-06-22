<?php
  ini_set('display_errors', 0);
  error_reporting(0);

  require_once __DIR__ . '/../../ots/constant.php';

  if (session_status() != PHP_SESSION_ACTIVE) {
    session_start();
  }
  $_SESSION[GN_LAST_ACTIVE] = time();

  require_once __DIR__ . '/../../ots/session_handler.php';

  if (!isset($_SESSION[GC_LOGIN_COOKIE]))
  {
    header("Location: ../login.php");
    exit;
  }

  if (!isset($_SESSION[GN_CHURCH_ID]) || $_SESSION[GN_CHURCH_ID] <= 0)
  {
    header("Location: ../login.php");
    exit;
  }

  // A Webix keretrendszer betöltése (abszolút /ots/ útvonalakkal, mert nincs virtuális host)
  $ots_root = '/ots';
  $mc_skin = GetCurrentSkin();
  $mc_lang = GetCurrentLanguage();
  $skin_css = GetSkinCSS();
  echo '<!DOCTYPE html>' . "\n";
  echo '<html lang="' . $mc_lang . '">' . "\n";
  echo '  <head>' . "\n";
  echo '  <meta name="robots" content="noindex" />' . "\n";
  echo '  <meta http-equiv="Content-Type" content="text/html; charset=utf-8" />' . "\n";
  echo '  <title>Revizor Asszisztens 1.0 – OTS Tranzakciók</title>' . "\n";
  echo '  <script type="text/javascript">webix_skin = "' . $mc_skin . '";</script>' . "\n";
  echo '  <script src="' . $ots_root . '/ots_icons.js" type="text/javascript" charset="utf-8"></script>' . "\n";
  echo '  <link rel="stylesheet" href="' . $ots_root . '/3rdparty/webix/' . $skin_css . '.css">' . "\n";
  echo '  <script src="' . $ots_root . '/3rdparty/webix/webix.min.js" type="text/javascript" charset="utf-8"></script>' . "\n";
  echo '  <script src="' . $ots_root . '/3rdparty/webix/i18n/' . $mc_lang . '.js" type="text/javascript" charset="utf-8"></script>' . "\n";
  echo '  <link rel="stylesheet" href="' . $ots_root . '/3rdparty/fontawesome/css/fontawesome.min.css">' . "\n";
  echo '  <link rel="stylesheet" href="' . $ots_root . '/3rdparty/fontawesome/css/solid.min.css">' . "\n";
  echo '  <script type="text/javascript">top.gc_DisplayLanguage = "' . $mc_lang . '";</script>' . "\n";
  echo '  <script src="' . $ots_root . '/i18n/' . $mc_lang . '.js" type="text/javascript" charset="utf-8"></script>' . "\n";
  echo '  <script src="' . $ots_root . '/language.js" type="text/javascript" charset="utf-8"></script>' . "\n";
  echo '  <link rel="stylesheet" href="' . $ots_root . '/css/ots.css">' . "\n";
  echo '  <link rel="stylesheet" href="' . $ots_root . '/css/ots_' . $mc_skin . '.css">' . "\n";
  echo '  <script src="' . $ots_root . '/penztar_utils.js" type="text/javascript" charset="utf-8"></script>' . "\n";
  echo '</head>' . "\n";
  echo '<body>' . "\n";
?>
<div style="display:flex;align-items:center;justify-content:space-between;padding:6px 12px;background:#fff;border-bottom:1px solid #dee2e6;font-family:sans-serif;font-size:14px;">
    <div style="display:flex;align-items:center;gap:8px;">
        <span style="font-weight:700;">🕵️ Revizor Asszisztens 1.0</span>
        <span style="color:#6c757d;">|</span>
        <span style="color:#6c757d;">OTS Tranzakciók</span>
    </div>
    <div style="display:flex;align-items:center;gap:4px;">
        <a href="../index.php" style="text-decoration:none;padding:2px 8px;border:1px solid #6c757d;border-radius:4px;color:#6c757d;font-size:13px;">🏠 Kezdőlap</a>
        <a href="../help.php" style="text-decoration:none;padding:2px 8px;border:1px solid #0d6efd;border-radius:4px;color:#0d6efd;font-size:13px;">❓ Súgó</a>
        <a href="../logout.php" style="text-decoration:none;padding:2px 8px;border:1px solid #dc3545;border-radius:4px;color:#dc3545;font-size:13px;margin-left:4px;">Kilépés</a>
    </div>
</div>
<style>
.export_page_title .webix_el_box {
  font-size: 20px;
  font-weight: 700;
  text-align: center;
  letter-spacing: 0;
}
</style>
<script type="text/javascript">
function cleanExcelText(value) {
  if (typeof value !== "string") return value;
  return value.replace(/[\x00-\x08\x0B\x0C\x0E-\x1F]/g, " ");
}

function cleanExcelFormulaText(value) {
  value = cleanExcelText(String(value || ""));
  return /^[=+\-@]/.test(value) ? "'" + value : value;
}

function escapeCell(value) {
  value = value == null ? "" : String(value);
  if (webix.template && webix.template.escape) {
    return webix.template.escape(value);
  }
  return value.replace(/[&<>"']/g, function(ch) {
    return {"&": "&amp;", "<": "&lt;", ">": "&gt;", "\"": "&quot;", "'": "&#39;"}[ch];
  });
}

function cleanTableDataForExcel(table) {
  var fields = ["RECEIPT_NUMBER", "DECISION_NUMBER", "DATETIME", "DESCRIPTION", "NAME", "EDITOR"];
  table.data.each(function(row) {
    for (var i = 0; i < fields.length; i++) {
      if (row[fields[i]]) row[fields[i]] = cleanExcelFormulaText(row[fields[i]]);
    }
  });
}

function formatDateYMD(value) {
  if (!value) value = new Date();
  if (value instanceof Date) {
    return webix.Date.dateToStr("%Y-%m-%d")(value);
  }
  return String(value).substring(0, 10).replace(/[\.\/]/g, "-");
}

function formatDateDots(value) {
  if (!value) return "";
  if (value instanceof Date) {
    return webix.Date.dateToStr("%Y.%m.%d")(value);
  }
  return String(value).substring(0, 10).replace(/[-\/]/g, ".");
}

function safeFileNamePart(value) {
  return cleanExcelText(String(value || "Gyulekezet"))
    .replace(/[\\\/:*?"<>|]+/g, "_")
    .replace(/\s+/g, "_")
    .replace(/^_+|_+$/g, "") || "Gyulekezet";
}

function getCookieValue(name) {
  var cookies = document.cookie ? document.cookie.split(";") : [];
  var prefix = name + "=";
  for (var i = 0; i < cookies.length; i++) {
    var part = cookies[i].replace(/^\s+/, "");
    if (part.indexOf(prefix) === 0) {
      return decodeURIComponent(part.substring(prefix.length));
    }
  }
  return "";
}

function setCookieValue(name, value, days) {
  var expires = new Date();
  expires.setTime(expires.getTime() + (days * 24 * 60 * 60 * 1000));
  document.cookie = name + "=" + encodeURIComponent(value) + "; expires=" + expires.toUTCString() + "; path=/; SameSite=Lax";
}

function setCookieValueHours(name, value, hours) {
  var expires = new Date();
  expires.setTime(expires.getTime() + (hours * 60 * 60 * 1000));
  document.cookie = name + "=" + encodeURIComponent(value) + "; expires=" + expires.toUTCString() + "; path=/; SameSite=Lax";
}

function dateFromYMD(value) {
  if (!/^\d{4}-\d{2}-\d{2}$/.test(value || "")) return null;
  var parts = value.split("-");
  return new Date(Number(parts[0]), Number(parts[1]) - 1, Number(parts[2]));
}

function savedStartDate() {
  return dateFromYMD(getCookieValue("OTS_BANK_EXPORT_V2_START_DATE"));
}

function savedChurchId(defaultChurchId) {
  var churchId = getCookieValue("OTS_ALL_TRANSACTIONS_MULTI_CHURCH_ID");
  return /^\d+$/.test(churchId || "") ? Number(churchId) : defaultChurchId;
}

function savedFlow() {
  var flow = getCookieValue("OTS_ALL_TRANSACTIONS_MULTI_FLOW");
  return /^(bank|cash|both)$/.test(flow || "") ? flow : "bank";
}

function rememberShortSelections(values) {
  setCookieValueHours("OTS_ALL_TRANSACTIONS_MULTI_CHURCH_ID", values.church_id || "", 2);
  setCookieValueHours("OTS_ALL_TRANSACTIONS_MULTI_FLOW", values.flow || "bank", 2);
}

function showLoadError(err, url) {
  var status = err && err.status ? " HTTP " + err.status : "";
  var message = "Az adatok letöltése nem sikerült." + status + " Kérjük, frissítsd az oldalt vagy jelezd az üzemeltetőnek.";
  webix.message({type: "error", text: cleanExcelText(message), expire: 15000});
}

function flowLabel(flow) {
  if (flow === "cash") return "Keszpenz";
  if (flow === "both") return "Bank_es_keszpenz";
  return "Bank";
}

function getCleanExportRows(table) {
  var rows = [];
  table.eachRow(function(rowId) {
    var row = table.getItem(rowId);
    rows.push({
      RECEIPT_NUMBER: cleanExcelFormulaText(row.RECEIPT_NUMBER || ""),
      DECISION_NUMBER: cleanExcelFormulaText(row.DECISION_NUMBER || ""),
      DATETIME: cleanExcelFormulaText(formatDateDots(row.DATETIME)),
      FLOW: cleanExcelText(row.FLOW || ""),
      DESCRIPTION: cleanExcelFormulaText(row.DESCRIPTION || ""),
      NAME: cleanExcelFormulaText(row.NAME || ""),
      SUMAMOUNT: isNaN(Number(row.SUMAMOUNT)) ? 0 : Number(row.SUMAMOUNT),
      EDITOR: cleanExcelFormulaText(row.EDITOR || ""),
      balance: isNaN(Number(row.balance)) ? 0 : Number(row.balance)
    });
  });
  return rows;
}

function exportCleanExcel(filename) {
  var tempId = "data_table_excel_export";
  if ($$(tempId)) {
    $$(tempId).destructor();
  }

  var temp = webix.ui({
    view: "datatable",
    id: tempId,
    hidden: true,
    columns: [
      { id: "RECEIPT_NUMBER", header: "Bizonylatszám", width: 120 },
      { id: "DECISION_NUMBER", header: "Biz. határozati szám", width: 150 },
      { id: "DATETIME", header: "Dátum", width: 100 },
      { id: "FLOW", header: "Forgalom", width: 110 },
      { id: "DESCRIPTION", header: "Partner / Megjegyzes", width: 320 },
      { id: "NAME", header: "Tipus", width: 180 },
      { id: "SUMAMOUNT", header: "Osszeg", width: 120, exportType: "number", exportFormat: "#,##0" },
      { id: "EDITOR", header: "Rogzitette", width: 150 },
      { id: "balance", header: "Egyenleg", width: 120, exportType: "number", exportFormat: "#,##0" }
    ],
    data: getCleanExportRows($$("data_table"))
  });

  webix.toExcel(temp, {
    filename: filename,
    name: "Tranzakciok",
    rawValues: true
  });

  window.setTimeout(function() {
    if ($$(tempId)) {
      $$(tempId).destructor();
    }
  }, 2000);
}

webix.ui.datafilter.rowCount = {
  refresh: function(master, node, config) {
    var count = master.count();
    var total = master.data && master.data.pull ? Object.keys(master.data.pull).length : count;
    node.innerHTML = "<div style='text-align:left; font-weight:bold; padding-left:10px;'>Képernyőn lévő sorok: " + count + " (Letöltött rekordok: " + total + ")</div>";
  },
  render: function(master, config) {
    return "<div style='text-align:left; font-weight:bold; padding-left:10px;'>Képernyőn lévő sorok: 0</div>";
  }
};

webix.ui.datafilter.lastBalance = {
  refresh: function(master, node, config) {
    var lastId = master.getLastId();
    var lastVal = 0;
    if (lastId) {
      var item = master.getItem(lastId);
      if (item && item.balance !== undefined) lastVal = item.balance;
    }
    node.innerHTML = webix.i18n.intFormat(lastVal);
  },
  render: function(master, config) {
    return "0";
  }
};

// Több kulcsszavas OR szűrő a Partner / Megjegyzés oszlophoz.
function splitMultiFilterTokens(value) {
  return String(value || "")
    .toLowerCase()
    .replace(/\b(vagy|or)\b/g, " ")
    .split(/[\s,;|]+/)
    .filter(function(token) {
      return token.length > 0;
    });
}

function multiTextFilterCompare(value, filter, item) {
  var tokens = splitMultiFilterTokens(filter);
  if (tokens.length === 0) return true;

  var hay = (value == null ? "" : String(value)).toLowerCase();
  for (var i = 0; i < tokens.length; i++) {
    if (hay.indexOf(tokens[i]) !== -1) return true;
  }
  return false;
}

webix.ui.datafilter.multiTextFilter = webix.extend({
  refresh: function(master, node, config) {
    config.compare = multiTextFilterCompare;
    return webix.ui.datafilter.textFilter.refresh.call(this, master, node, config);
  },
  compare: function(value, filter, item) {
    var tokens = splitMultiFilterTokens(filter);
    if (tokens.length === 0) return true;

    var hay = (value == null ? "" : String(value)).toLowerCase();
    for (var i = 0; i < tokens.length; i++) {
      if (hay.indexOf(tokens[i]) !== -1) return true;
    }
    return false;
  }
}, webix.ui.datafilter.textFilter);

function updateNavLabel() {
  var table = $$("data_table");
  if (!table) return;
  var selected = table.getSelectedId();
  var all = table.order ? table.order : [];
  if (all.length === 0) { $$("nav_label").setValue("0 / 0"); return; }
  var idx = selected ? all.indexOf(selected) + 1 : 0;
  $$("nav_label").setValue(idx + " / " + all.length);
}

webix.ready(function(){
  // Egyedi Webix felület az exportáláshoz
  webix.ui({
    rows: [
      {
        view: "label",
        id: "page_title",
        label: "OTS Tranzakciók Lekérdezése",
        align: "center",
        height: 44,
        css: "export_page_title"
      },
      {
        view: "form",
        id: "export_form",
        padding: 15,
        elements: [
          {
            cols: [
              { view: "datepicker", label: "Kezdő dátum:", name: "start_date", format: "%Y.%m.%d", stringResult: true, value: savedStartDate(), labelWidth: 105, width: 220 },
              { view: "datepicker", label: "Befejező dátum:", name: "end_date", format: "%Y.%m.%d", stringResult: true, value: new Date(), labelWidth: 115, width: 230 },
              { view: "combo", label: "Gyülekezet:", name: "church_id", options: "/ots/church_for_combo.php?userole=1", value: savedChurchId(<?php echo intval($_SESSION[GN_CHURCH_ID]); ?>), labelWidth: 90, width: 270 },
              { view: "segmented", label: "Forgalom:", name: "flow", value: savedFlow(), options: [
                  { id: "bank", value: "<span class='webix_icon fas fa-university' title='Bank'></span>" },
                  { id: "cash", value: "<span class='webix_icon fas fa-money-bill-wave' title='Készpénz'></span>" },
                  { id: "both", value: "<span class='webix_icon fas fa-university' title='Mindkettő'></span> <span class='webix_icon fas fa-money-bill-wave' title='Mindkettő'></span>" }
                ], labelWidth: 80, width: 180 },
              {}, // üres hely
              { view: "button", value: "Szűrők törlése", width: 115, click: function() {
                  var table = $$("data_table");
                  table.eachColumn(function(id, col) {
                      var filter = table.getFilter(id);
                      if (filter) filter.value = "";
                  });
                  table.filterByAll();
              }},
              { view: "button", value: "Lekérdezés", css: "webix_primary", width: 115, click: function() {
                  var vals = $$("export_form").getValues();

                  if (!vals.start_date || !vals.church_id) {
                    webix.message({type: "error", text: "Kérjük, add meg a gyülekezetet és a kezdő dátumot!"});
                    return;
                  }

                  webix.message("Adatok lekérése folyamatban...");
                  $$("data_table").clearAll();

                  var start_str = formatDateYMD(vals.start_date);
                  var end_str = formatDateYMD(vals.end_date);
                  var flow = vals.flow || "bank";
                  var data_url = "all_transactions_multi_dt.php?start=" + encodeURIComponent(start_str) + "&end=" + encodeURIComponent(end_str) + "&church_id=" + encodeURIComponent(vals.church_id) + "&flow=" + encodeURIComponent(flow);
                  setCookieValue("OTS_BANK_EXPORT_V2_START_DATE", start_str, 365);
                  rememberShortSelections(vals);
                  $$("data_table").load(data_url)
                  .fail(function(err) {
                      showLoadError(err, data_url);
                  });
              }},
              { view: "button", value: "Exportálás Excelbe", width: 155, click: function() {
                  var vals = $$("export_form").getValues();
                  if ($$("data_table").count() === 0) {
                    webix.message({type: "error", text: "Nincs mit exportálni! Először futtass egy lekérdezést."});
                    return;
                  }
                  var start_str = vals.start_date ? formatDateYMD(vals.start_date) : 'kezdet';
                  var end_str = formatDateYMD(vals.end_date);
                  var flow = vals.flow || "bank";
                  var church_combo = $$("export_form").elements.church_id;
                  var church_name = church_combo ? church_combo.getText() : vals.church_id;
                  var church_prefix = safeFileNamePart(church_name);
                  cleanTableDataForExcel($$("data_table"));
                  exportCleanExcel(church_prefix + "_OTS_" + flowLabel(flow) + "_Tranzakciok_" + start_str + "_tol_" + end_str);
              }}
            ]
          }
        ]
      },
      {
        view: "toolbar",
        padding: 4,
        elements: [
          {},
          {
            view: "button", type: "icon", icon: "fas fa-chevron-left", width: 40,
            click: function() {
              var table = $$("data_table");
              var selected = table.getSelectedId();
              if (!selected) { table.select(table.getFirstId()); return; }
              var prev = table.getPrevId(selected);
              if (prev) { table.select(prev); table.showItem(prev); updateNavLabel(); }
            }
          },
          {
            view: "label", id: "nav_label", label: "0 / 0", align: "center", width: 100
          },
          {
            view: "button", type: "icon", icon: "fas fa-chevron-right", width: 40,
            click: function() {
              var table = $$("data_table");
              var selected = table.getSelectedId();
              if (!selected) { table.select(table.getFirstId()); return; }
              var next = table.getNextId(selected);
              if (next) { table.select(next); table.showItem(next); updateNavLabel(); }
            }
          },
          {}
        ]
      },
      {
        // Adatok megjelenítése
        view: "datatable",
        id: "data_table",
        autoConfig: true,
        footer: true,
        select: "row",
        columns: [
            { id: "RECORD_ID", header: "", width: 0, hidden: true },
            { id: "RECEIPT_NUMBER", header: ["Bizonylatszám", { content: "textFilter" }], width: 120, sort: "string", template: function(obj) { return escapeCell(obj.RECEIPT_NUMBER); }, footer: "" },
            { id: "DECISION_NUMBER", header: ["Biz. határozati szám", { content: "textFilter" }], width: 150, sort: "string", template: function(obj) { return escapeCell(obj.DECISION_NUMBER); }, footer: "" },
            { id: "DATETIME", header: ["Dátum", { content: "textFilter" }], width: 105, sort: "string", template: function(obj) { return escapeCell(formatDateDots(obj.DATETIME)); }, footer: "" },
            { id: "FLOW", header: ["Forgalom", { content: "selectFilter" }], width: 120, sort: "string", template: function(obj) { return escapeCell(obj.FLOW); }, footer: "" },
            { id: "DESCRIPTION", header: ["Partner / Megjegyzés", { content: "multiTextFilter", placeholder: "szó vagy másik" }], fillspace: true, sort: "string", template: function(obj) { return escapeCell(obj.DESCRIPTION); }, footer: "" },
            { id: "NAME", header: ["Típus", { content: "textFilter" }], width: 220, sort: "string", template: function(obj) { return escapeCell(obj.NAME); }, footer: "" },
            { id: "SUMAMOUNT", header: ["Összeg", { content: "textFilter" }], width: 150, sort: "int", format: webix.i18n.intFormat, css: { "text-align": "right" }, exportType: "number", exportFormat: "#,##0", footer: { content: "summColumn", css: { "text-align": "right" } } },
            { id: "EDITOR", header: ["Rögzítette", { content: "selectFilter" }], width: 150, sort: "string", template: function(obj) { return escapeCell(obj.EDITOR); }, footer: "" },
            { id: "balance", header: ["Egyenleg", ""], width: 150, sort: "int", format: webix.i18n.intFormat, css: { "text-align": "right" }, exportType: "number", exportFormat: "#,##0", footer: { content: "lastBalance", css: { "text-align": "right" } } }
        ],
        on: {
            onAfterLoad: function() {
                this.sort("DATETIME", "asc", "date");
                updateNavLabel();
            },
            onSelectChange: function() {
                updateNavLabel();
            }
        }
      }
    ]
  });

  webix.delay(function() {
    var form = $$("export_form");
    if (!form) return;

    var church = form.elements.church_id;
    var flow = form.elements.flow;
    if (church) {
      church.attachEvent("onChange", function() {
        rememberShortSelections(form.getValues());
      });
    }
    if (flow) {
      flow.attachEvent("onChange", function() {
        rememberShortSelections(form.getValues());
      });
    }
  }, null, null, 100);

  // record_id URL param kezelése — OTS-ből ugrás
  var urlRecordId = (new URLSearchParams(window.location.search)).get('record_id');
  if (urlRecordId) {
    var now = new Date();
    var start = new Date(now.getFullYear(), 0, 1);
    var form = $$("export_form");
    if (form) {
      form.setValues({ start_date: start, end_date: now });
      webix.delay(function() {
        var btn = form.queryView({ view: "button", value: "Lekérdezés" });
        if (btn) btn.callEvent("onItemClick", []);
      }, null, null, 500);
    }
    // Adatok betöltődése után keressük a record_id-t
    var dt = $$("data_table");
    if (dt) {
      dt.attachEvent("onAfterLoad", function() {
        var found = dt.find(function(obj) { return String(obj.RECORD_ID) === urlRecordId; });
        if (found.length > 0) {
          dt.select(found[0].id);
          dt.showItem(found[0].id);
          updateNavLabel();
          webix.message({ type: "info", text: "Rekord #" + urlRecordId + " kiválasztva" });
        } else {
          webix.message({ type: "error", text: "Rekord #" + urlRecordId + " nem található az időablakban" });
        }
      });
    }
  }
});
</script>
<div style="position:fixed; top:10px; left:10px; z-index:9999;">
  <a href="../index.php" class="btn btn-outline-secondary btn-sm">🏠 Kezdőlap</a>
  <a href="../logout.php" class="btn btn-outline-danger btn-sm ms-1">Kilépés</a>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

</body>
</html>
