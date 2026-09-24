import { describe, expect, it } from 'vitest';

import { acceptAttribute, checkFile, extensionOf, fileKind, formatBytes } from '../../resources/js/lib/files';

describe('formatBytes', () => {
    it('picks a readable unit', () => {
        expect(formatBytes(512, 'en')).toBe('512B');
        expect(formatBytes(1536, 'en')).toBe('1.5 kB');
        expect(formatBytes(25 * 1024 * 1024, 'en')).toBe('25 MB');
        expect(formatBytes(null, 'en')).toBe('');
    });
});

describe('extensionOf', () => {
    it('reads the last extension in lower case', () => {
        expect(extensionOf('Reading.PDF')).toBe('pdf');
        expect(extensionOf('archive.tar.gz')).toBe('gz');
        expect(extensionOf('README')).toBe('');
        expect(extensionOf('folder.v2/file')).toBe('');
    });
});

describe('checkFile', () => {
    const rules = { maxSize: 1024, extensions: ['png', 'pdf'] };

    it('refuses types off the list and files over the limit', () => {
        expect(checkFile({ name: 'tool.exe', size: 10 }, rules)).toBe('type');
        expect(checkFile({ name: 'chart.png', size: 2048 }, rules)).toBe('size');
        expect(checkFile({ name: 'chart.PNG', size: 1024 }, rules)).toBeNull();
    });
});

describe('acceptAttribute', () => {
    it('lists extensions for the file picker', () => {
        expect(acceptAttribute(['png', 'pdf'])).toBe('.png,.pdf');
    });
});

describe('fileKind', () => {
    it('groups types for their icon', () => {
        expect(fileKind('image/png')).toBe('image');
        expect(fileKind('audio/mpeg')).toBe('audio');
        expect(fileKind('video/mp4')).toBe('video');
        expect(fileKind('application/pdf')).toBe('pdf');
        expect(fileKind('text/plain')).toBe('text');
        expect(fileKind('application/vnd.openxmlformats-officedocument.wordprocessingml.document')).toBe('document');
        expect(fileKind(null)).toBe('file');
    });
});
