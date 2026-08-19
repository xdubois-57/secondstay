import { deflateSync } from 'node:zlib';

/**
 * Génère un PNG minimal valide en mémoire, sans dépendance ni fichier
 * binaire versionné dans le dépôt.
 */
export function pngBuffer(width = 64, height = 48, colour = [40, 90, 160]) {
    const crcTable = [];
    for (let n = 0; n < 256; n++) {
        let c = n;
        for (let k = 0; k < 8; k++) {
            c = c & 1 ? 0xedb88320 ^ (c >>> 1) : c >>> 1;
        }
        crcTable[n] = c >>> 0;
    }

    const crc32 = (buffer) => {
        let c = 0xffffffff;
        for (const byte of buffer) {
            c = crcTable[(c ^ byte) & 0xff] ^ (c >>> 8);
        }
        return (c ^ 0xffffffff) >>> 0;
    };

    const chunk = (type, data) => {
        const length = Buffer.alloc(4);
        length.writeUInt32BE(data.length);
        const typeBuffer = Buffer.from(type, 'ascii');
        const crc = Buffer.alloc(4);
        crc.writeUInt32BE(crc32(Buffer.concat([typeBuffer, data])));
        return Buffer.concat([length, typeBuffer, data, crc]);
    };

    const ihdr = Buffer.alloc(13);
    ihdr.writeUInt32BE(width, 0);
    ihdr.writeUInt32BE(height, 4);
    ihdr[8] = 8; // profondeur
    ihdr[9] = 2; // RGB
    ihdr[10] = 0;
    ihdr[11] = 0;
    ihdr[12] = 0;

    const raw = Buffer.alloc(height * (1 + width * 3));
    for (let y = 0; y < height; y++) {
        const rowStart = y * (1 + width * 3);
        raw[rowStart] = 0;
        for (let x = 0; x < width; x++) {
            const offset = rowStart + 1 + x * 3;
            raw[offset] = (colour[0] + x) % 256;
            raw[offset + 1] = (colour[1] + y) % 256;
            raw[offset + 2] = colour[2];
        }
    }

    const idat = deflateSync(raw);

    return Buffer.concat([
        Buffer.from([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a]),
        chunk('IHDR', ihdr),
        chunk('IDAT', idat),
        chunk('IEND', Buffer.alloc(0))
    ]);
}
