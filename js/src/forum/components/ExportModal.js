import app from 'flarum/forum/app';
import Modal from 'flarum/common/components/Modal';
import Button from 'flarum/common/components/Button';
import Switch from 'flarum/common/components/Switch';
import Icon from 'flarum/common/components/Icon';

const t = (key, params) => app.translator.trans(`ernestdefoe-folio.forum.modal.${key}`, params);

/** Remembered between exports, so a reader who always wants Word gets Word. */
const PREFS_KEY = 'folio.exportPrefs';

function loadPrefs() {
  try {
    return JSON.parse(localStorage.getItem(PREFS_KEY) || '{}') || {};
  } catch (e) {
    return {};
  }
}

export default class ExportModal extends Modal {
  oninit(vnode) {
    super.oninit(vnode);

    const formats = app.forum.attribute('folioFormats') || [];
    const prefs = loadPrefs();

    this.formats = formats;
    this.format = formats.some((f) => f.key === prefs.format) ? prefs.format : formats[0]?.key;
    this.scope = prefs.scope === 'first' ? 'first' : 'all';
    this.authors = prefs.authors !== false;
    this.dates = prefs.dates !== false;
    this.avatars = prefs.avatars === true;
    this.error = null;
  }

  className() {
    return 'FolioModal Modal--small';
  }

  title() {
    return t('title');
  }

  current() {
    return this.formats.find((f) => f.key === this.format) || this.formats[0];
  }

  content() {
    const discussion = this.attrs.discussion;
    const single = discussion.commentCount() <= 1;

    return (
      <div className="Modal-body">
        <div className="Form">
          <div className="Form-group">
            <label>{t('format')}</label>
            <div className="FolioModal-formats" role="radiogroup">
              {this.formats.map((f) => (
                <button
                  type="button"
                  role="radio"
                  aria-checked={f.key === this.format ? 'true' : 'false'}
                  className={'FolioModal-format' + (f.key === this.format ? ' active' : '')}
                  onclick={() => (this.format = f.key)}
                >
                  <Icon name={f.icon} />
                  <span>{f.label}</span>
                </button>
              ))}
            </div>
          </div>

          {single ? null : (
            <div className="Form-group">
              <label>{t('contents')}</label>
              <div className="FolioModal-segment" role="radiogroup">
                {['all', 'first'].map((s) => (
                  <button
                    type="button"
                    role="radio"
                    aria-checked={s === this.scope ? 'true' : 'false'}
                    className={s === this.scope ? 'active' : ''}
                    onclick={() => (this.scope = s)}
                  >
                    {t(s === 'all' ? 'scope_all' : 'scope_first')}
                  </button>
                ))}
              </div>
            </div>
          )}

          <div className="Form-group">
            <label>{t('include')}</label>
            <Switch state={this.authors} onchange={(v) => (this.authors = v)}>
              {t('authors')}
            </Switch>
            <Switch state={this.dates} onchange={(v) => (this.dates = v)}>
              {t('dates')}
            </Switch>
            {this.current()?.avatars ? (
              <Switch state={this.avatars} disabled={!this.authors} onchange={(v) => (this.avatars = v)}>
                {t('avatars')}
              </Switch>
            ) : null}
          </div>

          {this.error ? <p className="FolioModal-error">{this.error}</p> : null}

          <div className="Form-group">
            <Button className="Button Button--primary Button--block" icon="fas fa-download" loading={this.loading} onclick={() => this.download()}>
              {t('download')}
            </Button>
            <p className="FolioModal-note">{t('private_note')}</p>
          </div>
        </div>
      </div>
    );
  }

  /**
   * Fetched rather than navigated to, so a refusal (the rate limit, a revoked
   * permission) is a message in the dialog instead of a browser tab showing a
   * JSON error.
   */
  async download() {
    const discussion = this.attrs.discussion;
    const params = {
      format: this.format,
      scope: this.scope,
      authors: this.authors ? 1 : 0,
      dates: this.dates ? 1 : 0,
      avatars: this.avatars && this.authors ? 1 : 0,
    };

    try {
      localStorage.setItem(PREFS_KEY, JSON.stringify({ format: this.format, scope: this.scope, authors: this.authors, dates: this.dates, avatars: this.avatars }));
    } catch (e) {
      // Private mode: the choice just isn't remembered.
    }

    this.loading = true;
    this.error = null;
    m.redraw();

    try {
      const url = `${app.forum.attribute('apiUrl')}/folio/discussions/${discussion.id()}?${m.buildQueryString(params)}`;
      const response = await fetch(url, { credentials: 'same-origin', headers: { 'X-CSRF-Token': app.session.csrfToken } });

      if (!response.ok) {
        this.error = t(response.status === 429 ? 'throttled' : 'failed');
        return;
      }

      const blob = await response.blob();
      const name = this.filename(response.headers.get('Content-Disposition')) || `discussion-${discussion.id()}`;
      const link = document.createElement('a');

      link.href = URL.createObjectURL(blob);
      link.download = name;
      document.body.appendChild(link);
      link.click();
      link.remove();
      setTimeout(() => URL.revokeObjectURL(link.href), 10000);

      this.hide();
    } catch (e) {
      this.error = t('failed');
    } finally {
      this.loading = false;
      m.redraw();
    }
  }

  filename(header) {
    if (!header) return null;

    const star = /filename\*=UTF-8''([^;]+)/i.exec(header);
    if (star) {
      try {
        return decodeURIComponent(star[1]);
      } catch (e) {
        // fall through to the plain name
      }
    }

    const plain = /filename="([^"]+)"/i.exec(header);
    return plain ? plain[1] : null;
  }
}
