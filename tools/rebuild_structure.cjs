#!/usr/bin/env node
/**
 * Rebuild a template's structure_json.json from extracted coords.
 *
 * Input:
 *   <coords.json>        — from extract_coords.cjs
 *   <existing_structure> — current structure_json.json (for bg + anim types)
 * Output:
 *   <out_structure> — rewritten structure_json with exact positions + fonts
 *
 * Usage: node rebuild_structure.cjs <coords.json> <existing.json> <out.json> [slug]
 */
const fs = require('fs');

const [coordsPath, existingPath, outPath, slug] = process.argv.slice(2);
if (!coordsPath || !existingPath || !outPath) {
  process.stderr.write('Usage: rebuild_structure.cjs <coords.json> <existing.json> <out.json> [slug]\n');
  process.exit(2);
}
const coords = JSON.parse(fs.readFileSync(coordsPath, 'utf8'));
const existing = JSON.parse(fs.readFileSync(existingPath, 'utf8'));

function cleanFont(f){ return (f || '').replace(/['"]/g, '').split(',')[0].trim() || 'Inter'; }

// Normalize any CSS color to a #rrggbb hex (alpha dropped — FFmpeg drawtext
// uses the fontcolor hex without alpha, alpha handled separately).
function toHex(c){
  if (!c) return '#FFFFFF';
  c = String(c).trim();
  if (c[0] === '#'){
    if (c.length === 7) return c.toUpperCase();
    if (c.length === 4) return ('#' + c[1]+c[1]+c[2]+c[2]+c[3]+c[3]).toUpperCase();
  }
  var m = /^rgba?\(\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)/i.exec(c);
  if (m){
    var h = function(n){ n = parseInt(n,10); return ('0'+n.toString(16)).slice(-2); };
    return ('#' + h(m[1]) + h(m[2]) + h(m[3])).toUpperCase();
  }
  return '#FFFFFF';
}

// Build a lookup of existing text_overlays by field-name (parsed from id "t_xxx")
const existingByField = {};
(existing.text_overlays || []).forEach(function(t){
  var f = (t.id || '').replace(/^t_/, '');
  existingByField[f] = t;
});

const duration = existing.duration || 12;
const text_overlays = [];

Object.keys(coords.fields).forEach(function(field){
  var c = coords.fields[field];
  var old = existingByField[field] || {};
  var animType = (old.animation_in && old.animation_in.type) || 'slide_up';
  var start = c.anim_delay != null ? c.anim_delay : (old.start_time != null ? old.start_time : 1.0);
  var animDur = c.anim_duration ? Math.min(1.5, Math.max(0.3, c.anim_duration)) : 0.5;
  text_overlays.push({
    id:           't_' + field,
    element_type: c.element_type || old.element_type || 'subtext',
    content:      c.content || (old.content || ''),
    color:        toHex(c.color || old.color || '#FFFFFF'),
    font_size:    Math.round(c.font_size || 32),
    font_weight:  String(c.font_weight || old.font_weight || '700'),
    font_family:  cleanFont(c.font_family),
    anchor:       c.anchor || 'left',
    position:     { x: c.x, y: c.y },
    bbox:         c.bbox || null,
    start_time:   start,
    end_time:     duration,
    animation_in: { type: animType, duration: animDur },
  });
});

// Preserve existing BG clip and other structure
const out = Object.assign({}, existing, {
  text_overlays: text_overlays,
});

fs.writeFileSync(outPath, JSON.stringify(out, null, 2));
process.stdout.write('rebuilt ' + (slug || outPath) + ' with ' + text_overlays.length + ' text_overlays\n');
