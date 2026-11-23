// Module boundaries for v4. The graph is the deptrac graph in ./deptrac.yaml, module for module;
// it is empty of edges until the modules land (S0.3+), so today this only forbids what is already true.
/** @type {import('dependency-cruiser').IConfiguration} */
module.exports = {
  forbidden: [
    {
      name: "no-circular",
      severity: "error",
      from: {},
      to: { circular: true },
    },
    {
      name: "no-php-tree",
      comment: "v4 never imports from the PHP app on the same branch",
      severity: "error",
      from: { path: "^v4/" },
      to: { pathNot: "^v4/" },
    },
    {
      name: "no-dev-from-src",
      severity: "error",
      from: { path: "^v4/src/" },
      to: { path: "^v4/test/" },
    },
  ],
  options: {
    doNotFollow: { path: "node_modules" },
    tsConfig: { fileName: "v4/tsconfig.json" },
    reporterOptions: { dot: { collapsePattern: "node_modules/[^/]+" } },
  },
};
