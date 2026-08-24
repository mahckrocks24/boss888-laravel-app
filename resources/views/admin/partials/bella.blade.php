  <!-- Bella AI Floating Button (Bella Session 2) -->
  <button class="bella-toggle" id="bella-toggle-btn" onclick="toggleBella()" title="Chat with Bella AI">
    <span class="bella-toggle-pulse"></span>&#10024; Bella
  </button>

  <!-- Bella overlay disabled — non-modal so admin can browse while chatting -->
  <div class="bella-overlay" id="bella-overlay" style="display:none"></div>

  <!-- Bella AI Chat Panel (Bella Session 2 — full widget) -->
  <div class="bella-panel" id="bella-panel">
    <div class="bella-header">
      <div class="bella-avatar">B</div>
      <div class="bella-header-info">
        <div class="bella-header-name">Bella</div>
        <div class="bella-header-role">EXECUTIVE ASSISTANT</div>
      </div>
      <div style="display:flex;gap:6px;margin-left:auto">
        <button class="bella-close" onclick="bellaToggleArtifacts()" title="Artifacts" style="font-size:14px">&#128193;</button>
        <button class="bella-close" onclick="toggleBella()" title="Close">&#215;</button>
      </div>
    </div>

    <!-- Artifacts slide-over (hidden by default) -->
    <div id="bella-artifacts-panel" style="display:none;flex:1;overflow-y:auto;padding:12px">
      <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:10px">
        <span style="font-size:13px;font-weight:600;color:var(--text)">Generated Artifacts</span>
        <button class="bella-close" onclick="bellaToggleArtifacts()" style="font-size:11px">Back to chat</button>
      </div>
      <div id="bella-artifacts-list" style="font-size:12px;color:var(--muted)">Loading...</div>
    </div>

    <!-- Chat area -->
    <div id="bella-chat-area" style="display:flex;flex-direction:column;flex:1;min-height:0">
      <div class="bella-messages" id="bella-messages">
        <div class="bella-welcome" id="bella-welcome">
          <div class="bella-welcome-avatar">B</div>
          <h3>Hi, I'm Bella</h3>
          <p>Your executive assistant.<br>I can analyze screenshots, generate reports, manage credits, and more.</p>
        </div>
      </div>
      <div class="bella-quick-actions">
        <button class="bella-quick-btn" onclick="bellaQuickAction('report')">&#128202; Report</button>
        <button class="bella-quick-btn" onclick="bellaQuickAction('users')">&#128101; Users</button>
        <button class="bella-quick-btn" onclick="bellaQuickAction('credits')">&#128176; Credits</button>
        <button class="bella-quick-btn" onclick="bellaQuickAction('queue')">&#9889; Queue</button>
      </div>

      <!-- Image preview (shown when image attached) -->
      <div id="bella-image-preview" style="display:none;padding:4px 16px;background:var(--s2)">
        <div style="display:flex;align-items:center;gap:8px">
          <img id="bella-preview-thumb" style="width:48px;height:48px;object-fit:cover;border-radius:6px;border:1px solid var(--border)" src="">
          <span id="bella-preview-name" style="font-size:11px;color:var(--muted);flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"></span>
          <button onclick="bellaClearImage()" style="background:none;border:none;color:var(--rd);cursor:pointer;font-size:14px">&#215;</button>
        </div>
      </div>

      <div class="bella-input-area">
        <!-- Hidden file input for image upload -->
        <input type="file" id="bella-file-input" accept="image/png,image/jpeg,image/webp" style="display:none" onchange="bellaFileSelected(this)">
        <button onclick="document.getElementById('bella-file-input').click()" title="Attach image" style="background:none;border:none;color:var(--muted);cursor:pointer;font-size:16px;padding:4px 6px;flex-shrink:0">&#128206;</button>
        <!-- Generate dropdown -->
        <div style="position:relative;flex-shrink:0">
          <button id="bella-gen-btn" onclick="bellaToggleGenMenu()" title="Generate" style="background:none;border:none;color:var(--am);cursor:pointer;font-size:14px;padding:4px 6px">&#9889;</button>
          <div id="bella-gen-menu" style="display:none;position:absolute;bottom:36px;left:0;background:var(--s2);border:1px solid var(--border);border-radius:8px;padding:4px;min-width:160px;z-index:10;box-shadow:0 4px 16px rgba(0,0,0,.4)">
            <button onclick="bellaGenerate('Image')" style="display:block;width:100%;text-align:left;background:none;border:none;color:var(--text);padding:6px 10px;font-size:12px;cursor:pointer;border-radius:4px" onmouseover="this.style.background='var(--s3)'" onmouseout="this.style.background='none'">&#127912; Image</button>
            <button onclick="bellaGenerate('Document')" style="display:block;width:100%;text-align:left;background:none;border:none;color:var(--text);padding:6px 10px;font-size:12px;cursor:pointer;border-radius:4px" onmouseover="this.style.background='var(--s3)'" onmouseout="this.style.background='none'">&#128196; Document</button>
            <button onclick="bellaGenerate('Presentation')" style="display:block;width:100%;text-align:left;background:none;border:none;color:var(--text);padding:6px 10px;font-size:12px;cursor:pointer;border-radius:4px" onmouseover="this.style.background='var(--s3)'" onmouseout="this.style.background='none'">&#128202; Presentation</button>
            <button onclick="bellaGenerate('Video')" style="display:block;width:100%;text-align:left;background:none;border:none;color:var(--text);padding:6px 10px;font-size:12px;cursor:pointer;border-radius:4px" onmouseover="this.style.background='var(--s3)'" onmouseout="this.style.background='none'">&#127909; Video</button>
          </div>
        </div>
        <textarea class="bella-input" id="bella-input" placeholder="Ask Bella anything..." rows="1" onkeydown="if(event.key==='Enter'&&!event.shiftKey){event.preventDefault();bellaSend();}"></textarea>
        <button class="bella-send" id="bella-send-btn" onclick="bellaSend()">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="22" y1="2" x2="11" y2="13"></line><polygon points="22 2 15 22 11 13 2 9 22 2"></polygon></svg>
        </button>
      </div>
    </div>
  </div>

