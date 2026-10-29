import { test, expect } from './fixtures';
import * as crypto from 'node:crypto';
import * as fs from 'node:fs';
import * as path from 'node:path';

/** A JPEG whose bytes include an APP1 segment and the marker GPSLatitude. */
function markedJpeg(app: { server: { filesDir: string; at: (p: string) => string; php: (args: string[]) => void } }, name: string): string {
  const out = path.join(app.server.filesDir, name);
  if (!fs.existsSync(out)) {
    app.server.php(['-r', `$im=imagecreatetruecolor(80,60);imagefill($im,0,0,imagecolorallocate($im,40,80,120));
      ob_start();imagejpeg($im);$j=ob_get_clean();
      $payload="Exif\\x00\\x00GPSLatitude Make Model";
      file_put_contents($argv[1],"\\xFF\\xD8\\xFF\\xE1".pack('n',strlen($payload)+2).$payload.substr($j,2));`, app.server.at(out)]);
  }
  return out;
}

test.describe('camera originals', () => {
  test('a photo under the limit is stored byte for byte and the served copy has no EXIF', async ({ app, page }) => {
    const file = markedJpeg(app, 'orig-exif.jpg');
    const picked = crypto.createHash('sha256').update(fs.readFileSync(file)).digest('hex');
    await app.go('/new');
    await page.locator('#itemform .photo-lib input[type=file]').setInputFiles(file);
    await expect(page.locator('#pstatus')).toContainText('KB');
    await expect(page.locator('#photo-limit')).toHaveCount(0);
    await page.locator('#name').fill('Kept original');
    await page.locator('#save').click();
    await page.waitForURL(/saved=\d+/);
    const img = app.sql<{ image: string }>("SELECT image FROM items WHERE name = 'Kept original'")[0].image;
    const stem = img.slice(0, 32);
    const base = fs.readFileSync(path.join(app.server.dataDir, 'originals', 'map', stem), 'utf8').trim();
    const orig = fs.readFileSync(path.join(app.server.dataDir, 'originals', base));
    expect(crypto.createHash('sha256').update(orig).digest('hex')).toBe(picked);
    const served = fs.readFileSync(path.join(app.server.dataDir, 'images', img));
    expect(served.includes(Buffer.from('GPSLatitude'))).toBe(false);
    expect(served.includes(Buffer.from([0xff, 0xe1]))).toBe(false);
  });

  test('a photo over the upload limit says the original is not kept', async ({ app, page }) => {
    app.settings({ max_upload_mb: 1 });
    const small = markedJpeg(app, 'orig-small.jpg');
    const big = path.join(app.server.filesDir, 'orig-over.jpg');
    fs.writeFileSync(big, Buffer.concat([fs.readFileSync(small), Buffer.alloc(1024 * 1024, 0x20)]));
    await app.go('/new');
    await page.locator('#itemform .photo-lib input[type=file]').setInputFiles(big);
    await expect(page.locator('#photo-limit')).toContainText('original file is not kept');
  });
});
