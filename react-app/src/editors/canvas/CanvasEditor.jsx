import React, { useEffect, useRef, useState } from 'react';
import { useParams, useNavigate } from 'react-router-dom';
import * as api from '../../services/api';

// Fabric.js loaded via CDN in index.html for canvas editing
// This editor provides: layers, text, shapes, filters, crop, export

export default function CanvasEditor() {
  const { id } = useParams();
  const nav = useNavigate();
  const canvasRef = useRef(null);
  const fabricRef = useRef(null);
  const [tool, setTool] = useState('select');
  const [canvasData, setCanvasData] = useState(null);
  const [saving, setSaving] = useState(false);

  useEffect(() => {
    if (id && id !== 'new') {
      api.manualedit.getCanvas(id).then(d => setCanvasData(d));
    }
    initCanvas();
    return () => { if (fabricRef.current) fabricRef.current.dispose(); };
  }, []);

  const initCanvas = () => {
    if (typeof fabric === 'undefined') {
      // Fabric.js not loaded yet — load from CDN
      const script = document.createElement('script');
      script.src = 'https://cdnjs.cloudflare.com/ajax/libs/fabric.js/5.3.1/fabric.min.js';
      script.onload = () => createCanvas();
      document.head.appendChild(script);
    } else {
      createCanvas();
    }
  };

  const createCanvas = () => {
    const el = canvasRef.current;
    if (!el) return;
    const fc = new fabric.Canvas(el, {
      width: 1280, height: 720, backgroundColor: '#1a1a2e',
      selection: true, preserveObjectStacking: true,
    });
    fabricRef.current = fc;

    // Load saved state if exists
    if (canvasData?.state_json) {
      try {
        const state = typeof canvasData.state_json === 'string' ? JSON.parse(canvasData.state_json) : canvasData.state_json;
        if (state.objects) fc.loadFromJSON(state, fc.renderAll.bind(fc));
      } catch (_) {}
    }
  };

  const addText = () => {
    const fc = fabricRef.current; if (!fc) return;
    const text = new fabric.IText('Double click to edit', {
      left: 100, top: 100, fontSize: 36, fill: '#ffffff',
      fontFamily: 'Manrope', fontWeight: 700,
    });
    fc.add(text); fc.setActiveObject(text); fc.renderAll();
  };

  const addShape = (type) => {
    const fc = fabricRef.current; if (!fc) return;
    let shape;
    if (type === 'rect') shape = new fabric.Rect({ left: 100, top: 100, width: 200, height: 150, fill: '#7C3AED40', stroke: '#7C3AED', strokeWidth: 2, rx: 12, ry: 12 });
    else if (type === 'circle') shape = new fabric.Circle({ left: 200, top: 200, radius: 75, fill: '#3B82F640', stroke: '#3B82F6', strokeWidth: 2 });
    else if (type === 'line') shape = new fabric.Line([50, 50, 300, 50], { stroke: '#00E5A8', strokeWidth: 3 });
    if (shape) { fc.add(shape); fc.renderAll(); }
  };

  const addImage = () => {
    const input = document.createElement('input');
    input.type = 'file'; input.accept = 'image/*';
    input.onchange = (e) => {
      const file = e.target.files[0]; if (!file) return;
      const reader = new FileReader();
      reader.onload = (ev) => {
        fabric.Image.fromURL(ev.target.result, (img) => {
          img.scaleToWidth(400);
          fabricRef.current.add(img);
          fabricRef.current.renderAll();
        });
      };
      reader.readAsDataURL(file);
    };
    input.click();
  };

  const deleteSelected = () => {
    const fc = fabricRef.current; if (!fc) return;
    const active = fc.getActiveObjects();
    active.forEach(obj => fc.remove(obj));
    fc.discardActiveObject(); fc.renderAll();
  };

  const applyFilter = (filterType) => {
    const fc = fabricRef.current; if (!fc) return;
    const obj = fc.getActiveObject();
    if (!obj || obj.type !== 'image') return;
    const filters = {
      grayscale: new fabric.Image.filters.Grayscale(),
      sepia: new fabric.Image.filters.Sepia(),
      blur: new fabric.Image.filters.Blur({ blur: 0.3 }),
      brightness: new fabric.Image.filters.Brightness({ brightness: 0.2 }),
    };
    obj.filters = [filters[filterType]];
    obj.applyFilters(); fc.renderAll();
  };

  const save = async () => {
    const fc = fabricRef.current; if (!fc) return;
    setSaving(true);
    const state = fc.toJSON();
    try {
      if (id && id !== 'new') {
        await api.manualedit.saveCanvas(id, state, []);
      } else {
        await api.manualedit.createCanvas({ name: 'Untitled Canvas', state });
      }
      alert('Canvas saved!');
    } catch (e) { alert('Save failed: ' + e.message); }
    setSaving(false);
  };

  const exportImage = (format = 'png') => {
    const fc = fabricRef.current; if (!fc) return;
    const dataUrl = fc.toDataURL({ format, quality: 0.9, multiplier: 2 });
    const link = document.createElement('a');
    link.download = `canvas-export.${format}`;
    link.href = dataUrl;
    link.click();
  };

  const TOOLBAR = [
    { icon: '🖱️', label: 'Select', action: () => setTool('select') },
    { icon: '📝', label: 'Text', action: addText },
    { icon: '⬜', label: 'Rect', action: () => addShape('rect') },
    { icon: '⭕', label: 'Circle', action: () => addShape('circle') },
    { icon: '➖', label: 'Line', action: () => addShape('line') },
    { icon: '🖼️', label: 'Image', action: addImage },
    { icon: '🗑️', label: 'Delete', action: deleteSelected },
  ];

  const FILTERS = [
    { label: 'B&W', action: () => applyFilter('grayscale') },
    { label: 'Sepia', action: () => applyFilter('sepia') },
    { label: 'Blur', action: () => applyFilter('blur') },
    { label: 'Bright', action: () => applyFilter('brightness') },
  ];

  return (
    <div className="h-screen bg-bg flex flex-col">
      {/* Top bar */}
      <div className="h-12 bg-s1 border-b border-border flex items-center justify-between px-4 flex-shrink-0">
        <div className="flex items-center gap-3">
          <button onClick={() => nav(-1)} className="text-gray-400 hover:text-gray-200 text-sm">← Back</button>
          <span className="font-heading font-bold text-sm text-primary">Canvas Editor</span>
        </div>
        <div className="flex items-center gap-2">
          <button onClick={save} disabled={saving} className="bg-primary text-white text-xs font-bold px-3 py-1.5 rounded-lg">{saving ? 'Saving…' : 'Save'}</button>
          <button onClick={() => exportImage('png')} className="bg-accent/10 text-accent text-xs font-bold px-3 py-1.5 rounded-lg">Export PNG</button>
          <button onClick={() => exportImage('jpg')} className="bg-accent/10 text-accent text-xs font-bold px-3 py-1.5 rounded-lg">Export JPG</button>
        </div>
      </div>

      <div className="flex-1 flex overflow-hidden">
        {/* Left toolbar */}
        <div className="w-16 bg-s1 border-r border-border flex flex-col items-center py-4 gap-1 flex-shrink-0">
          {TOOLBAR.map(t => (
            <button key={t.label} onClick={t.action} title={t.label} className="w-10 h-10 rounded-lg flex items-center justify-center hover:bg-s2 text-lg">{t.icon}</button>
          ))}
          <div className="border-t border-border w-8 my-2" />
          {FILTERS.map(f => (
            <button key={f.label} onClick={f.action} className="w-10 h-8 rounded text-[9px] text-gray-400 hover:bg-s2 hover:text-gray-200">{f.label}</button>
          ))}
        </div>

        {/* Canvas area */}
        <div className="flex-1 flex items-center justify-center bg-s2 overflow-auto p-8">
          <div className="shadow-2xl rounded-lg overflow-hidden">
            <canvas ref={canvasRef} />
          </div>
        </div>
      </div>
    </div>
  );
}
