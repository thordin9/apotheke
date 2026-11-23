// The `Blobs` adapter (plan §3, §4): files, media and backups live in R2 hosted and in the disk
// bucket under `/data` self-host. The keys are the PHP app's, unchanged —
// `installations/<installation>/…` — so an existing bucket needs no migration and a backup ZIP
// contains the same names on both runtimes.
//
// The installation prefix is applied here, once, so no caller writes it and no caller can forget it.

export interface BlobOptions {
	/** `content-type` stored with the object, as the PHP `Files` layer does. */
	readonly contentType?: string;
}

export interface Blobs {
	get(key: string): Promise<ArrayBuffer | null>;
	put(key: string, body: ArrayBuffer | Uint8Array | string, options?: BlobOptions): Promise<void>;
	delete(key: string): Promise<void>;
	/** Keys (without the installation prefix), for tests and housekeeping. */
	list(prefix?: string): Promise<string[]>;
}

export class R2Blobs implements Blobs {
	constructor(
		private readonly bucket: R2Bucket,
		private readonly installation: string,
	) {}

	private full(key: string): string {
		return `installations/${this.installation}/${key}`;
	}

	async get(key: string): Promise<ArrayBuffer | null> {
		const object = await this.bucket.get(this.full(key));
		return object === null ? null : object.arrayBuffer();
	}

	async put(key: string, body: ArrayBuffer | Uint8Array | string, options?: BlobOptions): Promise<void> {
		const init: R2PutOptions | undefined =
			options?.contentType === undefined ? undefined : { httpMetadata: { contentType: options.contentType } };
		await this.bucket.put(this.full(key), body, init);
	}

	async delete(key: string): Promise<void> {
		await this.bucket.delete(this.full(key));
	}

	async list(prefix = ""): Promise<string[]> {
		const full = this.full(prefix);
		const listed = await this.bucket.list({ prefix: full });
		return listed.objects.map((o) => o.key.slice(this.full("").length));
	}
}
