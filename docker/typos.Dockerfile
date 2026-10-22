FROM python:3.15-slim-bookworm@sha256:575f8bc12ec26542e0678bc6b60e385218248019d433157ec327264a78c3a853
COPY typos-requirements.txt /tmp/typos-requirements.txt
RUN pip install --no-cache-dir --disable-pip-version-check --require-hashes -r /tmp/typos-requirements.txt \
  && rm /tmp/typos-requirements.txt \
  && groupadd --gid 10001 dev && useradd --uid 10001 --gid 10001 --no-create-home --shell /usr/sbin/nologin dev
USER 10001:10001
WORKDIR /work
ENTRYPOINT ["typos"]
